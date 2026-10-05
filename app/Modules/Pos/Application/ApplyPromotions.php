<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Domain\Enums\PosDiscountKind;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Domain\Contracts\PromotionResolver;
use App\Modules\Shared\Domain\Events\PosPromotionApplied;
use App\Modules\Shared\Domain\Promotions\LineSnapshot;
use App\Modules\Shared\Domain\Promotions\PromotionOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materializa las promociones de una cuenta en `pos_discounts`, al COBRAR o al DIVIDIR (§6.3, D310, D315, D366).
 *
 * ## Por qué al cobrar y no de forma continua
 *
 * `pos_discounts` es inmutable (append-only). Una promoción, en cambio, se re-evaluaría con cada cambio de la cuenta
 * —agregar una cerveza dispara el 2x1, pasar de las 8 apaga el happy hour—. Materializarla en cada recálculo o
 * duplicaría filas o rompería la inmutabilidad. La salida es evaluar una sola vez, cuando la cuenta ya no va a cambiar:
 * **al cobrar**. La vista previa durante la captura la calcula el resolver sin escribir nada; esto es lo que queda
 * grabado, y queda grabado una vez.
 *
 * Dividir es el otro momento en que la cuenta deja de cambiar (D366): la madre de una división no se cobra —se cobran
 * sus partes, que no tienen líneas a las que aplicar nada— y mientras la división viva no admite captura. Así que
 * `AccountOperations::split()` materializa aquí, justo antes de repartir, y cada parte ya lleva su porción del total con
 * la promoción aplicada.
 *
 * El resolver es puro (pregunta); esto es la escritura del efecto. La aritmética del total sigue viviendo en un solo
 * sitio, `CaptureOrderItems::recalculate()`: aquí sólo se crean las filas de descuento y se recalcula.
 *
 * ## Idempotente por LÍNEA
 *
 * Una línea que ya lleva su promoción no se vuelve a evaluar: cobrar en dos pagos no la aplica dos veces. Era por
 * cuenta —con una sola fila de promoción la cuenta entera quedaba cerrada— y dejó de bastar al materializar también al
 * dividir: una división que se deshace devuelve una cuenta normal con promociones grabadas en unas líneas, y las que
 * se capturen después se quedarían sin evaluar. Ver `pendingItems()`.
 *
 * ## No la autoriza nadie
 *
 * `authorized_by_membership_id` va null —el gancho que la Iteración 4 dejó a propósito—: una promoción es una regla, no
 * un acto que alguien firme con su PIN.
 */
final readonly class ApplyPromotions
{
    public function __construct(
        private PromotionResolver $resolver,
        private CaptureOrderItems $items,
    ) {}

    /**
     * La vista previa: qué promociones aplicarían a la cuenta AHORA, sin escribir nada (§6.3, paso 11 del diseño).
     *
     * Es lo que la pantalla de la cuenta pinta mientras se captura —«2x1: -$45»— antes de cobrar. El resolver es puro,
     * así que preguntarlo no tiene efecto: lo que quede grabado lo decide `materialize()` al cobrar, una sola vez.
     *
     * Sólo cuenta las líneas que TODAVÍA no llevan su promoción: las que ya la tienen viven en el total, y anunciarlas
     * otra vez las contaría dos veces. Y la madre de una división viva no previsualiza nada: sus partes ya llevan fijo
     * lo que aplicaba al dividir, y anunciar un descuento que nadie va a aplicar sería mentirle a quien cobra.
     */
    public function preview(PosAccount $account, CarbonImmutable $at): PromotionOutcome
    {
        if ($account->isSplit()) {
            return new PromotionOutcome();
        }

        $lineItems = $this->pendingItems($account);

        if ($lineItems->isEmpty()) {
            return new PromotionOutcome();
        }

        return $this->resolve($account, $lineItems, $at);
    }

    /**
     * Aplica las promociones vigentes a la cuenta y devuelve la cuenta recalculada.
     */
    public function materialize(PosAccount $account, int $actor, PosSession $session, CarbonImmutable $at): PosAccount
    {
        // Sólo las líneas que aún no llevan su promoción: cobrar en varios pagos no re-aplica (idempotencia por línea).
        $lineItems = $this->pendingItems($account);

        if ($lineItems->isEmpty()) {
            return $account;
        }

        $outcome = $this->resolve($account, $lineItems, $at);

        if ($outcome->isEmpty()) {
            return $account;
        }

        $itemsByUlid = $lineItems->keyBy('ulid');
        $eventos = [];

        foreach ($outcome->applied as $applied) {
            $item = $itemsByUlid->get($applied->itemUlid);

            if ($item === null) {
                continue;
            }

            $discount = PosDiscount::create([
                'pos_account_id' => $account->id,
                'pos_order_item_id' => $item->id,
                'kind' => PosDiscountKind::Amount,
                'source' => 'promotion',
                'promotion_ulid' => $applied->promotionUlid,
                'value' => $applied->resultingAmount,
                'resulting_amount' => $applied->resultingAmount,
                'reason' => $applied->name,
                'applied_by_membership_id' => $actor,
                // Sin autorizador: una promoción no la firma nadie (el gancho nullable de la Iteración 4).
                'authorized_by_membership_id' => null,
            ]);

            // El descuento de item vive en la línea: `line_total` es una columna generada que lo resta, igual que un
            // descuento manual de item.
            $item->update([
                'discount_amount' => bcadd((string) $item->discount_amount, $applied->resultingAmount, 2),
            ]);

            $eventos[] = new PosPromotionApplied(
                tenantId: (int) $account->tenant_id,
                branchId: (int) $account->branch_id,
                accountUlid: (string) $account->ulid,
                orderItemUlid: (string) $item->ulid,
                discountUlid: (string) $discount->ulid,
                promotionUlid: $applied->promotionUlid,
                amount: $applied->resultingAmount,
                posSessionId: (int) $session->id,
                appliedByMembershipId: $actor,
                appliedAt: $at->toIso8601String(),
            );
        }

        // Los eventos se despachan tras el commit: el registro por venta y el asiento del diario son efectos que pueden
        // llegar tarde, y ninguno puede tumbar el cobro (D220, D231).
        DB::afterCommit(function () use ($eventos): void {
            foreach ($eventos as $evento) {
                PosPromotionApplied::dispatch(
                    $evento->tenantId,
                    $evento->branchId,
                    $evento->accountUlid,
                    $evento->orderItemUlid,
                    $evento->discountUlid,
                    $evento->promotionUlid,
                    $evento->amount,
                    $evento->posSessionId,
                    $evento->appliedByMembershipId,
                    $evento->appliedAt,
                );
            }
        });

        return $this->items->recalculate($account);
    }

    /**
     * Las líneas cobrables que TODAVÍA no llevan su promoción, con la categoría de su artículo para que el motor pueda
     * apuntar a categorías sin volver a consultar.
     *
     * Es la llave de idempotencia: materializar dos veces la misma línea duplicaría el descuento, y previsualizar sobre
     * lo ya aplicado lo contaría dos veces. Filtrar por línea —y no preguntar si la cuenta ya tiene alguna promoción—
     * da lo mismo que evaluar la cuenta entera porque toda promoción de v1 se calcula sobre UNA línea (ver
     * `PromotionEngine`): ninguna depende de las demás. Una promoción que cruzara líneas —la agregación de NxM que el
     * motor deja como evolución— obligaría a revisar esto.
     *
     * @return Collection<int, PosOrderItem>
     */
    private function pendingItems(PosAccount $account): Collection
    {
        return PosOrderItem::query()
            ->where('pos_account_id', $account->id)
            ->billable()
            ->whereDoesntHave('discounts', fn ($query) => $query->fromPromotion())
            ->with('article:id,category_id')
            ->get();
    }

    /**
     * La pregunta al motor: qué promociones aplican a estas líneas, a esta hora, en esta sucursal. Pura — no escribe.
     *
     * @param  Collection<int, PosOrderItem>  $lineItems
     */
    private function resolve(PosAccount $account, Collection $lineItems, CarbonImmutable $at): PromotionOutcome
    {
        $snapshots = $lineItems->map(fn (PosOrderItem $item): LineSnapshot => new LineSnapshot(
            itemUlid: (string) $item->ulid,
            articleId: (int) $item->article_id,
            categoryId: $item->article?->category_id === null ? null : (int) $item->article->category_id,
            quantity: (string) $item->quantity,
            unitPrice: (string) $item->unit_price,
            lineTotal: (string) $item->line_total,
        ))->values()->all();

        // La zona de la sucursal viaja como primitivo: el motor evalúa la ventana horaria (§7) sin depender de
        // `Organization`.
        $timezone = (string) (Branch::query()->find($account->branch_id)?->timezone ?? 'UTC');

        return $this->resolver->resolveForAccount(
            (int) $account->branch_id,
            $at->toIso8601String(),
            $timezone,
            $snapshots,
        );
    }
}

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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materializa las promociones de una cuenta en `pos_discounts`, al COBRAR (§6.3, D310, D315).
 *
 * ## Por qué al cobrar y no de forma continua
 *
 * `pos_discounts` es inmutable (append-only). Una promoción, en cambio, se re-evaluaría con cada cambio de la cuenta
 * —agregar una cerveza dispara el 2x1, pasar de las 8 apaga el happy hour—. Materializarla en cada recálculo o
 * duplicaría filas o rompería la inmutabilidad. La salida es evaluar una sola vez, cuando la cuenta ya no va a cambiar:
 * **al cobrar**. La vista previa durante la captura la calcula el resolver sin escribir nada; esto es lo que queda
 * grabado, y queda grabado una vez.
 *
 * El resolver es puro (pregunta); esto es la escritura del efecto. La aritmética del total sigue viviendo en un solo
 * sitio, `CaptureOrderItems::recalculate()`: aquí sólo se crean las filas de descuento y se recalcula.
 *
 * ## Idempotente
 *
 * Si la cuenta ya tiene descuentos de origen promoción, no se vuelve a materializar: una división que se cobra en dos
 * pagos no aplica la promoción dos veces.
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
     * Aplica las promociones vigentes a la cuenta y devuelve la cuenta recalculada.
     */
    public function materialize(PosAccount $account, int $actor, PosSession $session, CarbonImmutable $at): PosAccount
    {
        // Ya materializada: una división que se cobra en varias partes no re-aplica (idempotencia).
        if (PosDiscount::query()->where('pos_account_id', $account->id)->fromPromotion()->exists()) {
            return $account;
        }

        $lineItems = PosOrderItem::query()
            ->where('pos_account_id', $account->id)
            ->billable()
            ->with('article:id,category_id')
            ->get();

        if ($lineItems->isEmpty()) {
            return $account;
        }

        $snapshots = $lineItems->map(fn (PosOrderItem $item): LineSnapshot => new LineSnapshot(
            itemUlid: (string) $item->ulid,
            articleId: (int) $item->article_id,
            categoryId: $item->article?->category_id === null ? null : (int) $item->article->category_id,
            quantity: (string) $item->quantity,
            unitPrice: (string) $item->unit_price,
            lineTotal: (string) $item->line_total,
        ))->values()->all();

        $timezone = (string) (Branch::query()->find($account->branch_id)?->timezone ?? 'UTC');

        $outcome = $this->resolver->resolveForAccount(
            (int) $account->branch_id,
            $at->toIso8601String(),
            $timezone,
            $snapshots,
        );

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
}

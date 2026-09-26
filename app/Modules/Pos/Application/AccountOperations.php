<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Pos\Domain\Enums\PosAccountOperationKind;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperation;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperationItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosPayment;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dividir, mover y juntar cuentas (§6.3, §4.5).
 *
 * ## Todo queda HISTORIZADO, y ésa es la razón de ser del paso
 *
 * Sin `pos_account_operations`, mover un item entre cuentas es indistinguible de haberlo capturado allí desde el
 * principio. Ése es el hueco por el que se va la mercancía en un bar: se capturan cuatro cervezas en la mesa 3, se
 * mueven tres a otra cuenta, esa cuenta se cancela, y la mesa 3 paga una. Nada en la línea delata el movimiento.
 *
 * ## Dividir NO reparte items: reparte el IMPORTE
 *
 * §6.3 pide «dividir por partes iguales», y repartir items sería no poder hacerlo: una botella que nadie pidió
 * individualmente no se puede asignar a nadie. Así que la división crea N subcuentas que cuelgan de la madre, cada una
 * con su parte del total, y **los items se quedan en la madre**.
 *
 * La subcuenta se cobra sola y emite su propio ticket. La madre queda pagada cuando todas sus partes lo están.
 *
 * ## Y mientras está dividida, la madre sólo se cobra por sus partes
 *
 * Es el invariante que hace honesto repartir importe: lo que suman las partes vivas ES el total de la madre. Por eso,
 * mientras la división viva, ni la madre ni las partes admiten nada que mueva un importe o una mercancía —captura,
 * descuentos, cobro directo de la madre, mover o juntar, cancelar la madre—, una parte no se cancela si su división ya
 * recibió dinero, y con una parte cancelada las demás ya no se cobran. Cancelar TODAS las partes deshace la división.
 * Lo que sí sigue igual es la cocina: lo capturado en la madre se comanda como siempre.
 *
 * ## Ninguna operación reescribe propinas ya pagadas
 *
 * Es lo que D233 compra al congelar `tip_membership_id` en la línea de pago. Juntar dos cuentas a las 22:00 no toca las
 * propinas cobradas a las 21:00: siguen siendo de quien las ganó. Por eso ninguna operación se permite sobre una cuenta
 * con pagos aplicados — mover items dejaría el dinero donde estaba y la mercancía en otro sitio.
 */
final readonly class AccountOperations
{
    public function __construct(
        private ContextHolder $context,
        private CaptureOrderItems $items,
        private AccountWorkflow $accounts,
        private DocumentNumberAllocator $folios,
        private AuditLogger $audit,
    ) {}

    /**
     * Divide una cuenta en N partes iguales.
     *
     * @return list<PosAccount> las subcuentas creadas
     */
    public function split(PosAccount $account, int $parts): array
    {
        $actor = $this->actor();

        return DB::transaction(function () use ($account, $parts, $actor): array {
            $madre = PosAccount::query()->whereKey($account->id)->with('restaurantTable')->lockForUpdate()->sole();

            // Las dos preguntas propias de dividir van ANTES que la general de «¿se puede operar?», que también rechaza
            // partes y cuentas divididas pero con un mensaje genérico.
            if ($madre->isSplitPart()) {
                throw PosAccountException::cannotSplitSubaccount();
            }

            // Sólo una división VIVA impide volver a dividir: si todas sus partes se cancelaron, la división se deshizo
            // y la cuenta se puede repartir de otra forma — que es lo que el mensaje de `alreadySplit` promete.
            if ($madre->isSplit()) {
                throw PosAccountException::alreadySplit($madre->displayName());
            }

            $this->assertOperable($madre);

            // Se reparte el total RECALCULADO, como al cerrar: repartir un total desactualizado sería cobrar en partes
            // otra cosa de lo que hay en la cuenta. Y el recálculo mueve la versión de la madre, que es lo que tiene que
            // pasar: a partir de aquí ya no se cobra ni se captura en ella, y una pantalla que la tuviera abierta desde
            // antes tiene que enterarse.
            $madre = $this->items->recalculate($madre);

            if (bccomp((string) $madre->total, '0', 2) <= 0) {
                throw PosAccountException::cannotSplitEmpty($madre->displayName());
            }

            $partes = $this->shares((string) $madre->total, $parts);

            $subcuentas = [];

            foreach ($partes as $indice => $importe) {
                $subcuentas[] = $this->createSubaccount($madre, $indice + 1, $parts, $importe, $actor);
            }

            $operacion = PosAccountOperation::create([
                'kind' => PosAccountOperationKind::Split,
                'source_account_id' => $madre->id,
                'performed_by_membership_id' => $actor,
                'detail_count' => $parts,
            ]);

            $this->audit->log(
                action: AuditAction::POS_ACCOUNT_SPLIT,
                auditable: $madre,
                after: [
                    'folio' => $madre->folioNumber(),
                    'parts' => $parts,
                    'total' => $madre->total,
                    'operation' => $operacion->ulid,
                ],
            );

            return $subcuentas;
        });
    }

    /**
     * Mueve items de una cuenta a otra.
     *
     * @param  list<string>  $itemUlids
     */
    public function moveItems(PosAccount $from, PosAccount $to, array $itemUlids): PosAccount
    {
        $actor = $this->actor();

        return DB::transaction(function () use ($from, $to, $itemUlids, $actor): PosAccount {
            [$origen, $destino] = $this->lockPair($from, $to);

            $this->assertOperable($origen);
            $this->assertOperable($destino);

            if ($origen->branch_id !== $destino->branch_id) {
                throw PosAccountException::accountsFromDifferentBranches();
            }

            $items = PosOrderItem::query()
                ->where('pos_account_id', $origen->id)
                ->whereIn('ulid', $itemUlids)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemUlids)) {
                throw PosAccountException::itemsNotInAccount($origen->displayName());
            }

            $operacion = PosAccountOperation::create([
                'kind' => PosAccountOperationKind::MoveItems,
                'source_account_id' => $origen->id,
                'target_account_id' => $destino->id,
                'performed_by_membership_id' => $actor,
                'detail_count' => $items->count(),
            ]);

            $this->recordDetail($operacion, $items, $origen->id, $destino->id);

            // Cambia `pos_account_id`. La ORDEN de lo ya comandado se queda donde estaba, porque describe lo que se
            // preparó: la comanda ya salió por la impresora de la cocina y ese hecho no se mueve (D263). Lo que todavía
            // no se comandaba sí se va a la orden borrador del destino — ver `moveUncommandedToDraft`.
            PosOrderItem::query()->whereIn('id', $items->pluck('id'))->update(['pos_account_id' => $destino->id]);

            $this->moveUncommandedToDraft($destino, $items, $actor);

            $this->items->recalculate($origen);
            $destino = $this->items->recalculate($destino);

            $this->audit->log(
                action: AuditAction::POS_ITEMS_MOVED,
                auditable: $origen,
                after: [
                    'from' => $origen->folioNumber(),
                    'to' => $destino->folioNumber(),
                    'items' => $items->pluck('article_name')->all(),
                    'operation' => $operacion->ulid,
                ],
            );

            // Si la cuenta de origen se quedó vacía, su mesa se libera: nadie va a cobrar nada ahí.
            //
            // SÓLO si se quedó vacía. `releaseTableIfEmpty` pregunta si queda OTRA cuenta viva en la mesa —se excluye a
            // sí misma, porque la usan el cobro y la cancelación, donde la cuenta ya dejó de estar viva—, así que
            // llamarla sin más liberaba la mesa aunque a la cuenta le quedaran artículos por cobrar, y dejaba sentar a
            // otro grupo encima de una cuenta viva.
            $quedaAlgoPorCobrar = PosOrderItem::query()->where('pos_account_id', $origen->id)->billable()->exists();

            if (! $quedaAlgoPorCobrar) {
                $this->accounts->releaseTableIfEmpty($origen->refresh());
            }

            return $destino;
        });
    }

    /**
     * Junta una cuenta en otra: todos los items pasan al destino.
     */
    public function merge(PosAccount $source, PosAccount $target): PosAccount
    {
        $actor = $this->actor();

        return DB::transaction(function () use ($source, $target, $actor): PosAccount {
            [$origen, $destino] = $this->lockPair($source, $target);

            if ($origen->id === $destino->id) {
                throw PosAccountException::cannotMergeIntoItself();
            }

            $this->assertOperable($origen);
            $this->assertOperable($destino);

            if ($origen->branch_id !== $destino->branch_id) {
                throw PosAccountException::accountsFromDifferentBranches();
            }

            $items = PosOrderItem::query()
                ->where('pos_account_id', $origen->id)
                ->lockForUpdate()
                ->get();

            $operacion = PosAccountOperation::create([
                'kind' => PosAccountOperationKind::Merge,
                'source_account_id' => $origen->id,
                'target_account_id' => $destino->id,
                'performed_by_membership_id' => $actor,
                'detail_count' => $items->count(),
            ]);

            $this->recordDetail($operacion, $items, $origen->id, $destino->id);

            PosOrderItem::query()->where('pos_account_id', $origen->id)->update(['pos_account_id' => $destino->id]);

            // Lo no comandado se va a la orden borrador del destino: la cuenta de origen queda cancelada, y si sus líneas
            // pendientes conservaran su orden nadie podría mandarlas a preparar nunca.
            $this->moveUncommandedToDraft($destino, $items, $actor);

            // La cuenta de origen deja de existir como algo que cobrar. Se marca CANCELADA con el motivo, y no «pagada»
            // —no entró dinero— ni se borra —ocurrió, y su historial la cita—. Es el estado honesto: ya no hay nada que
            // cobrar ahí, y el motivo dice a dónde se fue.
            $origen->update([
                'status' => PosAccountStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_reason' => sprintf('Juntada en la cuenta %s.', $destino->folioNumber()),
            ]);

            $this->items->recalculate($origen);
            $destino = $this->items->recalculate($destino);

            // El TITULAR que manda es el del destino (§4.3 del diseño), y el cambio queda escrito en la operación. Las
            // propinas ya cobradas no se tocan: están congeladas en sus líneas de pago (D233).
            $this->audit->log(
                action: AuditAction::POS_ACCOUNTS_MERGED,
                auditable: $destino,
                after: [
                    'from' => $origen->folioNumber(),
                    'to' => $destino->folioNumber(),
                    'items' => $items->count(),
                    'waiter_kept' => $destino->waiter_membership_id,
                    'operation' => $operacion->ulid,
                ],
            );

            $this->accounts->releaseTableIfEmpty($origen->refresh());

            return $destino;
        });
    }

    /**
     * Cómo se reparte un total en N partes iguales.
     *
     * ## El resto va a la PRIMERA parte, y tiene que ir a algún sitio
     *
     * 100 entre 3 son 33.33, 33.33 y 33.33: suman 99.99. El centavo que falta no puede evaporarse —la suma de las
     * partes tiene que ser exactamente el total, o el negocio cobra de menos en cada división— y no puede repartirse
     * «un poquito a cada uno» porque el peso no se divide más.
     *
     * Se le carga a la primera. Es arbitrario y es honesto: alguien paga el centavo, y decidirlo aquí evita que la
     * diferencia aparezca al final como un descuadre sin explicación.
     *
     * @return list<numeric-string>
     */
    private function shares(string $total, int $parts): array
    {
        $base = Decimal::round(bcdiv($total, (string) $parts, 6), 2);

        $partes = array_fill(0, $parts, $base);

        $suma = bcmul($base, (string) $parts, 2);
        $resto = bcsub($total, $suma, 2);

        $partes[0] = bcadd($partes[0], $resto, 2);

        return $partes;
    }

    /**
     * Una subcuenta: cuelga de la madre y lleva su parte del importe.
     */
    private function createSubaccount(
        PosAccount $madre,
        int $numero,
        int $de,
        string $importe,
        int $actor,
    ): PosAccount {
        $folio = $this->folios->next((int) $madre->branch_id, 'pos_account', 'A');

        return PosAccount::create([
            'branch_id' => $madre->branch_id,
            'series' => 'A',
            'folio' => $folio,
            'kind' => $madre->kind,

            // SIN mesa: la mesa la sigue ocupando la madre. Dos cuentas apuntando a la misma mesa harían que liberarla
            // dependiera de cuál se cobrara primero.
            'label' => sprintf('%s · parte %d de %d', $madre->displayName(), $numero, $de),

            'parent_account_id' => $madre->id,
            'waiter_membership_id' => $madre->waiter_membership_id,
            'opened_by_membership_id' => $actor,
            'opened_at' => CarbonImmutable::now(),

            // El importe se escribe UNA vez y no se recalcula: una subcuenta no tiene items propios, así que un
            // recálculo la dejaría en cero. Es la consecuencia de repartir importe y no mercancía.
            'subtotal' => $importe,
            'total' => $importe,
        ])->refresh();
    }

    /**
     * Lo que todavía NO se comandaba pasa a la orden borrador de la cuenta destino.
     *
     * ## Por qué lo no comandado sí cambia de orden y lo comandado no
     *
     * D263 deja la orden quieta porque «la comanda ya salió por la impresora de la cocina y ese hecho no cambia de
     * dueño». Eso es verdad de lo COMANDADO. Una línea capturada y sin comandar no tiene comanda: nadie la preparó, y su
     * orden es sólo el borrador de la ronda en curso de OTRA cuenta. Conservarla dejaba a la cuenta destino sin forma de
     * mandarla a preparar —la pantalla comanda por la orden de cada línea (D307), y esa orden no es suya: 409— y, al
     * juntar, la dejaba colgada de una cuenta cancelada. Comida pedida que nadie prepara, sin ningún error.
     *
     * Va a la orden borrador del destino —la misma a la que se anexaría una captura nueva—, así que sale en su próxima
     * comanda junto con lo que el destino tenga pendiente. El rastro del movimiento no se pierde: lo guarda el detalle de
     * la operación, línea por línea.
     *
     * @param  \Illuminate\Support\Collection<int, PosOrderItem>  $items  las líneas movidas, leídas ANTES de moverlas
     */
    private function moveUncommandedToDraft(PosAccount $destino, $items, int $actor): void
    {
        $pendientes = $items->filter(fn (PosOrderItem $item): bool => $item->status === PosOrderItemStatus::Captured);

        if ($pendientes->isEmpty()) {
            return;
        }

        // El destino ya está bloqueado (`lockPair`), que es lo que `draftOrderFor` exige para leer la secuencia.
        $borrador = $this->items->draftOrderFor($destino, $actor);

        PosOrderItem::query()->whereIn('id', $pendientes->pluck('id'))->update(['pos_order_id' => $borrador->id]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PosOrderItem>  $items
     */
    private function recordDetail(PosAccountOperation $operacion, $items, int $desde, int $hacia): void
    {
        foreach ($items as $item) {
            PosAccountOperationItem::create([
                'operation_id' => $operacion->id,
                'pos_order_item_id' => $item->id,
                'from_account_id' => $desde,
                'to_account_id' => $hacia,
            ]);
        }
    }

    /**
     * Bloquea las dos cuentas SIEMPRE en el mismo orden.
     *
     * Dos operaciones simultáneas que muevan items entre A y B en direcciones opuestas se bloquearían mutuamente si
     * cada una tomara primero «su» cuenta. Ordenar por id hace que la segunda espere en lugar de morir por interbloqueo.
     *
     * @return array{0: PosAccount, 1: PosAccount}
     */
    private function lockPair(PosAccount $a, PosAccount $b): array
    {
        $ids = [$a->id, $b->id];
        sort($ids);

        // Con la mesa cargada: los mensajes de error nombran la cuenta —«Mesa M1»— y la carga perezosa está prohibida,
        // así que sin esto un 409 explicativo se convierte en un 500.
        $cuentas = PosAccount::query()
            ->whereIn('id', $ids)
            ->with('restaurantTable')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [$cuentas[$a->id], $cuentas[$b->id]];
    }

    /**
     * Una cuenta operable: viva y SIN pagos aplicados.
     *
     * Lo segundo es lo importante. Mover items de una cuenta que ya tiene pagos dejaría el dinero donde estaba y la
     * mercancía en otro sitio: el ticket ya impreso diría una cosa y la cuenta otra. Corregir un cobro es una reversa,
     * no una mudanza.
     */
    private function assertOperable(PosAccount $account): void
    {
        if (! $account->status->acceptsItems() && $account->status !== PosAccountStatus::Closed) {
            throw PosAccountException::accountNotOperable($account->displayName(), $account->status->label());
        }

        if (PosPayment::query()->where('pos_account_id', $account->id)->exists()) {
            throw PosAccountException::accountHasPayments($account->displayName());
        }

        // Ni la madre ni las partes de una división viva, en ningún extremo (D262). Sacarle mercancía a la madre la
        // cobraría allá Y aquí por sus partes; meterle mercancía, o meterla en una parte, la dejaría sin cobrar —las
        // partes están fijas y una parte no se recalcula—. Y juntar una parte en otra cuenta cancelaría su importe.
        if ($account->isSplitPart()) {
            throw PosAccountException::splitPartNotOperable($account->displayName());
        }

        if ($account->isSplit()) {
            throw PosAccountException::accountIsSplit($account->displayName());
        }
    }

    private function actor(): int
    {
        return (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosOrder;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Pos\Infrastructure\Models\PosTicketItem;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Comandar: mandar a preparar lo que se capturó (§6.3).
 *
 * ## Una comanda POR ÁREA, no una por orden
 *
 * Una orden de una cuenta puede tocar cocina, barra y postres. Cada área recibe su propio papel en su propia impresora,
 * porque cada una prepara sólo lo suyo: una comanda con las tres cosas obligaría a la cocina a leer la barra y a la
 * barra a leer los postres, y en hora pico eso es un plato olvidado. De ahí que una cuenta de tres órdenes pueda tener
 * hasta nueve comandas.
 *
 * ## Los items sin área también pasan a «comandado»
 *
 * Y no producen papel. Una cerveza que el mesero saca de la nevera no necesita que nadie prepare nada, pero **ya está en
 * la mesa**: dejarla en «capturado» para siempre haría que quitarla de la cuenta fuera un borrado sin rastro cada vez, y
 * lo que pasó es que alguien se llevó una cerveza. El hecho que marca «comandado» no es «la cocina lo recibió», es «esto
 * ya salió y el cliente lo tiene».
 *
 * ## Comandar es IDEMPOTENTE por diseño
 *
 * Sólo toma los items en estado `captured`. Volver a comandar una orden ya comandada no reimprime nada ni duplica
 * comandas: no hay items que tomar. Importa porque la red de un restaurante se cae, y el mesero vuelve a picar el botón
 * cuando no ve confirmación — que es exactamente el momento en que un sistema mal hecho manda la comida dos veces.
 *
 * Para reimprimir una comanda existe la reimpresión, que es otra acción y queda auditada con su contador.
 */
final readonly class CommandOrder
{
    public function __construct(
        private ContextHolder $context,
        private CaptureOrderItems $items,
    ) {}

    /**
     * Manda a preparar los items pendientes de una orden.
     *
     * @return list<PosTicket> las comandas emitidas — vacío si no había nada pendiente
     */
    public function command(PosOrder $order): array
    {
        $membershipId = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        return DB::transaction(function () use ($order, $membershipId): array {
            $pendientes = PosOrderItem::query()
                ->where('pos_order_id', $order->id)
                ->where('status', PosOrderItemStatus::Captured->value)
                ->lockForUpdate()
                ->get();

            if ($pendientes->isEmpty()) {
                return [];
            }

            $cuenta = $order->account;
            $ahora = CarbonImmutable::now();

            // Agrupados por área, con los sin área en su propio grupo. `groupBy` conserva el orden de llegada, así que
            // la comanda lista los platos como se capturaron — que es como el mesero los cantó.
            $porArea = $pendientes->groupBy(fn (PosOrderItem $item): string => (string) ($item->preparation_area_id ?? ''));

            $comandas = [];

            foreach ($porArea as $areaId => $items) {
                $areaId = $areaId === '' ? null : (int) $areaId;

                PosOrderItem::query()
                    ->whereIn('id', $items->pluck('id'))
                    ->update([
                        'status' => PosOrderItemStatus::Commanded->value,
                        'updated_at' => $ahora,
                    ]);

                // Sin área no hay papel: no hay impresora a la que mandarlo ni nadie esperándolo.
                if ($areaId === null) {
                    continue;
                }

                $comandas[] = $this->issue($order, $areaId, $items, $membershipId, $ahora);
            }

            // La cuenta no cambia de importes al comandar —los items ya estaban contados— pero SÍ cambia de contenido, y
            // el candado optimista tiene que enterarse: alguien que tenga la cuenta en pantalla desde antes de comandar
            // ya no está mirando lo mismo.
            $this->items->touchVersion($cuenta);

            return $comandas;
        });
    }

    /**
     * Emite una comanda para un área, con sus renglones.
     *
     * @param  \Illuminate\Support\Collection<int, PosOrderItem>  $items
     */
    private function issue(
        PosOrder $order,
        int $areaId,
        $items,
        int $membershipId,
        CarbonImmutable $ahora,
    ): PosTicket {
        $cuenta = $order->account;

        $ticket = PosTicket::create([
            'branch_id' => $cuenta->branch_id,
            'kind' => PosTicketKind::Command,
            'pos_account_id' => $cuenta->id,
            'pos_order_id' => $order->id,
            'preparation_area_id' => $areaId,
            'issued_by_membership_id' => $membershipId,
            'issued_at' => $ahora,
        ]);

        foreach ($items as $item) {
            PosTicketItem::create([
                'pos_ticket_id' => $ticket->id,
                'pos_order_item_id' => $item->id,

                // La cantidad de la línea entera: comandar de a partes es del paso 12 (dividir y mover), y hasta
                // entonces una línea sale completa. La columna existe desde ahora porque el detalle de un papel impreso
                // no se puede reinterpretar después: si mañana se comanda media línea, las comandas de hoy tienen que
                // seguir diciendo cuánto salió.
                'quantity' => $item->quantity,
            ]);
        }

        // Después del commit, para que nadie imprima una comanda de una transacción que se va a deshacer. Es la misma
        // disciplina del paso 6 con los eventos de caja.
        DB::afterCommit(function () use ($ticket, $cuenta, $order, $areaId, $membershipId, $ahora): void {
            PosOrderCommanded::dispatch(
                (int) $ticket->tenant_id,
                (string) $ticket->ulid,
                (int) $ticket->id,
                (int) $cuenta->branch_id,
                (string) $cuenta->ulid,
                $cuenta->displayName(),
                (int) $order->sequence,
                $areaId,
                $membershipId,
                $ahora->toIso8601String(),
            );
        });

        return $ticket->refresh();
    }
}

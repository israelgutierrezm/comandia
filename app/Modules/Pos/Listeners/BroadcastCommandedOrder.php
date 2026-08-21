<?php

declare(strict_types=1);

namespace App\Modules\Pos\Listeners;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Events\Broadcast\AreaOrderCommanded;
use App\Modules\Shared\Domain\Events\Broadcast\FloorChanged;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Lo comandado, a la pantalla de la cocina y al piso.
 *
 * ## Por qué este traductor vive en `Pos` y el del piso en el kernel
 *
 * Porque necesita **las líneas**, y las líneas están en `pos_ticket_items`. `PosOrderCommanded` no las lleva: lleva el
 * ULID del ticket, que es lo que sus otros oyentes —impresión— necesitan. Un traductor en el kernel tendría que leer
 * tablas del POS para completarlas, que es la misma frontera cruzada por otra puerta.
 *
 * La regla que sí se respeta: el **contrato de difusión** vive en el kernel. Este módulo lo llena, no lo define.
 *
 * ## Dos avisos, dos públicos
 *
 * A la **cocina** le va la comanda con su contenido, por un canal de área: quien lo oye es quien prepara, y lo que
 * lleva es lo que ya tendría impreso en papel.
 *
 * Al **piso** le va sólo la señal de que esa mesa cambió, sin contenido. Sin este segundo aviso, la cuenta de artículos
 * que el piso pinta sobre la mesa se quedaría vieja: comandar no cambia el estado de la mesa —sigue ocupada—, así que
 * `TableStateChanged` no se emite y la pantalla no tendría de qué enterarse. Con el sondeo apagado por tener socket,
 * ese número se quedaría congelado toda la noche.
 *
 * ## Y no lleva dinero, ni siquiera aquí
 *
 * Ni precios ni total. La comanda de papel tampoco los lleva: a quien cocina no le sirven y a quien pasa por la cocina
 * no le incumben.
 */
final readonly class BroadcastCommandedOrder
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(PosOrderCommanded $event): void
    {
        // El oyente puede correr en la cola, sin sesión: el contexto se abre con el tenant que trae el evento (D231).
        $this->tenants->runFor($event->tenantId(), function () use ($event): void {
            $ticket = PosTicket::query()
                ->with(['items.item.modifiers', 'account.restaurantTable'])
                ->where('ulid', $event->ticketUlid)
                ->first();

            if ($ticket === null) {
                return;
            }

            $tenantUlid = Tenant::query()->whereKey($event->tenantId())->value('ulid');
            $branchUlid = Branch::query()->whereKey($event->branchId)->value('ulid');

            if ($tenantUlid === null || $branchUlid === null) {
                return;
            }

            $this->avisarAlPiso((string) $tenantUlid, (string) $branchUlid, $ticket, $event);

            // Sin área no hay cocina que avisar: es una comanda de mostrador. El piso sí se enteró.
            if ($event->preparationAreaId === null) {
                return;
            }

            $areaUlid = PreparationArea::query()->whereKey($event->preparationAreaId)->value('ulid');

            if ($areaUlid === null) {
                return;
            }

            AreaOrderCommanded::dispatch(
                (string) $tenantUlid,
                (string) $branchUlid,
                (string) $areaUlid,
                $event->ticketUlid,
                $event->orderSequence,
                $event->accountDisplayName,
                $this->lineas($ticket),
                $event->issuedAt,
            );
        });
    }

    private function avisarAlPiso(string $tenantUlid, string $branchUlid, PosTicket $ticket, PosOrderCommanded $event): void
    {
        $mesaUlid = $ticket->account?->restaurantTable?->ulid;

        // Una cuenta de barra o para llevar no está en el piso: avisar de ella haría recargar el salón por algo que no
        // se dibuja en él.
        if ($mesaUlid === null) {
            return;
        }

        FloorChanged::dispatch(
            $tenantUlid,
            $branchUlid,
            (string) $mesaUlid,
            $ticket->account?->restaurantTable?->status?->value,
            $event->accountUlid,
            'order_commanded',
        );
    }

    /**
     * Las líneas tal como las lee quien cocina.
     *
     * El nombre sale de `article_name`, que la orden **congeló** al capturar (§6.3): si el artículo se renombra
     * mañana, la comanda de hoy sigue diciendo lo que se pidió. Leer el catálogo aquí haría que una pantalla de cocina
     * y su comanda de papel dijeran cosas distintas del mismo plato.
     *
     * Los modificadores van con la línea porque son la mitad del trabajo: «sin cebolla» no es un adorno del pedido, es
     * lo que hay que hacer.
     *
     * @return list<array{name: string, quantity: string, notes: string|null}>
     */
    private function lineas(PosTicket $ticket): array
    {
        return $ticket->items
            ->map(function ($linea): array {
                $modificadores = $linea->item?->modifiers
                    ->map(fn ($m): string => (string) $m->modifier_name)
                    ->all() ?? [];

                return [
                    'name' => (string) ($linea->item?->article_name ?? '—'),
                    'quantity' => (string) $linea->quantity,
                    'notes' => $modificadores === [] ? null : implode(' · ', $modificadores),
                ];
            })
            ->values()
            ->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Http\Resources\PosTicketResource;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Shared\Domain\Events\PosTicketReprintRequested;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Qué se imprimió: comandas, tickets de cierre y tickets finales.
 */
final class PosTicketController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PosTicket>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['kind' => 'kind'],
            sortable: ['issued_at'],
            searchable: [],
            defaultSort: '-issued_at',
            dateRanges: ['issued' => 'issued_at'],
            handledByCaller: ['branch', 'account', 'area'],
        );

        $builder = $query->apply(
            PosTicket::query()->with([
                // `account.restaurantTable` y no sólo `account`: el nombre visible de una cuenta de mesa se arma con
                // el código de la mesa, así que pintar la lista la toca. Sin precargarla, el recurso intenta leerla
                // perezosamente y con el lazy loading deshabilitado eso es un 500 — el mismo defecto que D265, donde
                // `displayName()` convertía un 409 en un 500 por tocar una relación no cargada.
                'account.restaurantTable', 'order', 'preparationArea', 'issuedBy.user', 'issuedBy.employeeProfile',

                // Los renglones: la pantalla de cocina los necesita para poder preparar, y sin ellos tendría que
                // pedir cada comanda por separado — un parpadeo por platillo en la hora pico.
                'items.item',
            ]),
            $request,
        );

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('account')) {
            $builder->whereHas('account', fn ($q) => $q->where('ulid', $request->string('account')));
        }

        // «Qué se comandó a la cocina hoy», que es como se reconstruye la carga de un área y como se revisa por qué algo
        // no llegó.
        if ($request->filled('area')) {
            $builder->whereHas('preparationArea', fn ($q) => $q->where('ulid', $request->string('area')));
        }

        return PosTicketResource::collection($builder->paginate($query->perPage($request)));
    }

    public function show(PosTicket $posTicket): PosTicketResource
    {
        return new PosTicketResource($this->loaded($posTicket));
    }

    /**
     * Reimprimir.
     *
     * ## Es una acción, no un `GET` idempotente
     *
     * Cada reimpresión suma al contador y queda auditada, porque un papel que sale dos veces de la cocina es comida
     * preparada dos veces si alguien no se da cuenta. De ahí que sea `POST` aunque «no cambie datos del negocio»: sí los
     * cambia — cambia cuántas veces salió.
     *
     * ## Vuelve a despachar el evento, no reconstruye el papel
     *
     * El contenido de la comanda está en el ticket y en sus renglones, congelados. Reimprimir es pedir que el mismo
     * documento vuelva a salir, así que se despacha el mismo evento y quien imprime hace su trabajo. Reconstruir el
     * contenido aquí abriría la puerta a que la reimpresión dijera algo distinto del original, que es lo único que una
     * reimpresión no puede hacer.
     */
    public function reprint(PosTicket $posTicket): PosTicketResource
    {
        $posTicket->increment('reprint_count');
        $posTicket->refresh();

        $this->audit->log(
            action: AuditAction::POS_TICKET_REPRINTED,
            auditable: $posTicket,
            after: [
                'kind' => $posTicket->kind->value,
                'reprint_count' => $posTicket->reprint_count,
            ],
        );

        if ($posTicket->kind->goesToArea()) {
            PosOrderCommanded::dispatch(
                (int) $posTicket->tenant_id,
                (string) $posTicket->ulid,
                (int) $posTicket->id,
                (int) $posTicket->branch_id,
                (string) $posTicket->account->ulid,
                $posTicket->account->displayName(),
                (int) ($posTicket->order?->sequence ?? 0),
                $posTicket->preparation_area_id === null ? null : (int) $posTicket->preparation_area_id,
                (int) $posTicket->issued_by_membership_id,
                now()->toIso8601String(),
            );
        } else {
            // Recibo final o precuenta: no va a un área ni al KDS, sólo hay que volver a sacarlo por su impresora. Sin
            // esta rama, `reprint` incrementaba el contador de un recibo pero NO lo reimprimía (sólo las comandas salían).
            PosTicketReprintRequested::dispatch((int) $posTicket->tenant_id, (int) $posTicket->id);
        }

        return new PosTicketResource($this->loaded($posTicket));
    }

    private function loaded(PosTicket $ticket): PosTicket
    {
        return $ticket->load([
            'account',
            'order',
            'preparationArea',
            'issuedBy.user',
            'issuedBy.employeeProfile',
            'items.item',
        ]);
    }
}

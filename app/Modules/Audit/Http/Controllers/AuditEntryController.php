<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Http\Resources\AuditEntryResource;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;

/**
 * Consulta de la bitácora técnica (§6.7, D47).
 *
 * ## Paginación por CURSOR, no por página
 *
 * Es la primera tabla del proyecto de alto volumen —12 meses de todas las acciones sensibles de
 * un negocio— y §8 reserva el cursor justo para estos listados. La razón no es estética: con
 * `OFFSET`, MySQL tiene que recorrer y descartar todas las filas anteriores, así que la página
 * 500 cuesta 500 veces la primera. Y el `COUNT(*)` que la paginación por página necesita para
 * decir "de 340,000" recorre la tabla entera en cada petición.
 *
 * El cursor no permite saltar a la página 500, y eso está bien: nadie audita saltando a una
 * página arbitraria — se filtra por persona, por acción o por fecha.
 *
 * ## No hay endpoint de escritura, ni de borrado
 *
 * La bitácora es append-only (ARQUITECTURA_MAESTRA §7) y sólo escriben los servicios de dominio
 * a través de `AuditLogger`. Ausencia deliberada: exponer un endpoint de escritura permitiría
 * fabricar evidencia, que es exactamente lo contrario de para lo que existe esta tabla.
 */
final class AuditEntryController
{
    /**
     * @return AnonymousResourceCollection<CursorPaginator<int, AuditEntry>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [
                // El reporte que §9 exige como mitigación del robo hormiga: filtrar por acción
                // sobre un rango de fechas. Servido por `audit_entries_tenant_action_index`.
                'action' => 'action',
                'auditable_type' => 'auditable_type',
            ],
            // Sólo por fecha, y es a propósito: cualquier otro orden sobre una tabla de este
            // volumen sería un `filesort` sobre millones de filas. Los cuatro índices de la
            // tabla terminan todos en `created_at` justamente por esto.
            sortable: ['created_at'],
            searchable: [],
            // Descendente: una bitácora se abre para ver QUÉ ACABA DE PASAR.
            //
            // Antes se declaraba ascendente y se corregía después con un `reorder`, porque `ListQuery` no
            // sabía leer el prefijo `-` en el orden por omisión. El parche funcionaba y a la vez escondía dos
            // cosas: que el hueco existía, y que `reorder` DESCARTA el desempate por llave primaria — así que
            // el cursor de esta tabla se paginaba con un orden ambiguo cuando dos asientos caían en el mismo
            // segundo, que en una bitácora es lo normal.
            defaultSort: '-created_at',
            dateRanges: ['occurred' => 'created_at'],
            // Llega como ULID público y se traduce a la llave interna más abajo.
            handledByCaller: ['actor'],
        );

        $builder = $query->apply(
            AuditEntry::query()->with([
                'actor.user', 'actor.employeeProfile',
                'authorizedBy.user', 'authorizedBy.employeeProfile',
                'activeRole', 'branch', 'terminal',
            ]),
            $request,
        );

        // El filtro por persona se resuelve aparte porque llega como ULID: la API no expone
        // llaves primarias (§7), así que se traduce aquí en lugar de aceptar el id interno.
        $this->filterByActor($builder, $request);

        // El orden lo pone `ListQuery` con su desempate por llave, que es lo que hace estable el cursor.
        $entries = $builder->cursorPaginate($query->perPage($request));

        return AuditEntryResource::collection($entries);
    }

    public function show(AuditEntry $auditEntry): AuditEntryResource
    {
        return new AuditEntryResource($auditEntry->load([
            'actor.user', 'actor.employeeProfile',
            'authorizedBy.user', 'authorizedBy.employeeProfile',
            'activeRole', 'branch', 'terminal',
        ]));
    }

    /**
     * @param  Builder<AuditEntry>  $builder
     */
    private function filterByActor(Builder $builder, Request $request): void
    {
        $ulid = $request->string('actor')->trim()->toString();

        if ($ulid === '') {
            return;
        }

        $membership = TenantMembership::findByUlid($ulid);

        // Un ULID desconocido no devuelve la bitácora completa: filtra por un id imposible. La
        // alternativa —ignorar el filtro— mostraría todo a quien cree estar viendo las acciones
        // de una sola persona.
        $builder->where('actor_membership_id', $membership?->id ?? 0);
    }
}

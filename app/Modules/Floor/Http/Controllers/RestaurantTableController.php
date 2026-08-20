<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Application\JoinTables;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Http\Requests\JoinTablesRequest;
use App\Modules\Floor\Http\Requests\SaveRestaurantTableRequest;
use App\Modules\Floor\Http\Resources\RestaurantTableResource;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mesas del salón (§6.4).
 *
 * En esta iteración las mesas se dan de alta **por formulario**. El editor visual —arrastrarlas sobre el plano— es la
 * superficie de la Iteración 6 y exige ADR-003 más tiempo real.
 */
final class RestaurantTableController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JoinTables $joins,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, RestaurantTable>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['code', 'seats'],
            searchable: ['code', 'name'],
            defaultSort: 'code',
            handledByCaller: ['branch', 'zone', 'available_only'],
        );

        $builder = $query->apply(
            RestaurantTable::query()->with(['zone', 'joinedTo', 'joinedTables']),
            $request,
        );

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('zone')) {
            $builder->whereHas('zone', fn ($q) => $q->where('ulid', $request->string('zone')));
        }

        // «Dónde puedo sentar a alguien», que es la pregunta del anfitrión. Incluye no estar unida a otra mesa: una
        // mesa unida forma parte de un conjunto que atiende una sola cuenta.
        if ($request->boolean('available_only')) {
            $builder->available();
        }

        return RestaurantTableResource::collection($builder->paginate($query->perPage($request)));
    }

    public function store(SaveRestaurantTableRequest $request): JsonResponse
    {
        $zone = FloorZone::findByUlid($request->string('floor_zone_ulid')->toString());

        $table = RestaurantTable::create([
            // La sucursal se toma del PLANO de la zona y no del cuerpo de la petición: una mesa pertenece a la sucursal
            // donde está su salón, y dejar que el cliente la mande abriría la puerta a una mesa en la zona de otra
            // sucursal.
            'branch_id' => $zone?->plan?->branch_id,
            'floor_zone_id' => $zone?->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->filled('name') ? $request->string('name')->toString() : null,
            'seats' => $request->integer('seats', 4),
            'shape' => $request->string('shape', 'rectangle')->toString(),
        ]);

        $this->audit->log(
            action: AuditAction::TABLE_CREATED,
            auditable: $table,
            after: $table->only(['code', 'name', 'seats', 'floor_zone_id', 'status']),
        );

        return (new RestaurantTableResource($table->refresh()->load(['zone', 'joinedTo', 'joinedTables'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveRestaurantTableRequest $request, RestaurantTable $restaurantTable): RestaurantTableResource
    {
        $campos = ['name', 'seats', 'shape', 'x', 'y', 'width', 'height', 'rotation'];
        $before = $restaurantTable->only($campos);

        $restaurantTable->update($request->safe()->except(['floor_zone_ulid', 'code']));

        $this->audit->log(
            action: AuditAction::TABLE_UPDATED,
            auditable: $restaurantTable,
            before: $before,
            after: $restaurantTable->only($campos),
        );

        return new RestaurantTableResource($restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables']));
    }

    /**
     * Unir mesas (D32).
     */
    public function join(JoinTablesRequest $request, RestaurantTable $restaurantTable): RestaurantTableResource
    {
        /** @var list<RestaurantTable> $mesas */
        $mesas = RestaurantTable::query()
            ->whereIn('ulid', $request->input('table_ulids'))
            ->get()
            ->all();

        $this->joins->join($restaurantTable, $mesas);

        $this->audit->log(
            action: AuditAction::TABLES_JOINED,
            auditable: $restaurantTable,
            after: [
                'main' => $restaurantTable->code,
                'joined' => array_map(fn (RestaurantTable $t): string => (string) $t->code, $mesas),
            ],
        );

        return new RestaurantTableResource(
            $restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables'])
        );
    }

    /**
     * Separar las mesas unidas a ésta.
     *
     * Idempotente: separar una mesa que no tiene nada unido no es un error, es el estado deseado. Devolver 422 ahí haría
     * que la pantalla tuviera que saber si hay unión antes de ofrecer el botón.
     */
    public function separate(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        $unidas = $restaurantTable->joinedTables->pluck('code')->all();

        $this->joins->separate($restaurantTable);

        if ($unidas !== []) {
            $this->audit->log(
                action: AuditAction::TABLES_SEPARATED,
                auditable: $restaurantTable,
                before: ['joined' => $unidas],
            );
        }

        return new RestaurantTableResource(
            $restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables'])
        );
    }

    /**
     * Liberar una mesa a mano.
     *
     * ## Las dos únicas transiciones manuales, y por qué son sólo dos
     *
     * El estado de una mesa lo mueve **lo que pasa con sus cuentas**: se ocupa al abrir una, pasa a «cuenta solicitada»
     * al pedirla y se libera cuando todas están pagadas (§6.3). Dejar que alguien elija el estado de una lista haría que
     * el salón dejara de reflejar la realidad — una mesa marcada «libre» a mano con una cuenta abierta encima es la peor
     * información posible para quien atiende la puerta.
     *
     * Lo que sí se hace a mano es **marcar limpia** una mesa que espera limpieza, y **liberar** una que quedó ocupada
     * por error. Lo segundo se rechaza si hay servicio en curso de verdad, y eso lo comprobará el POS cuando existan las
     * cuentas: hoy la mesa no puede tener cuenta porque `pos_accounts` llega en el paso 7.
     */
    public function free(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        if ($restaurantTable->status === TableStatus::Free) {
            throw new ConflictHttpException('Esa mesa ya está libre.');
        }

        $before = ['status' => $restaurantTable->status->value];

        $restaurantTable->update(['status' => TableStatus::Free]);

        $this->audit->log(
            action: AuditAction::TABLE_UPDATED,
            auditable: $restaurantTable,
            before: $before,
            after: ['status' => TableStatus::Free->value],
        );

        return new RestaurantTableResource($restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables']));
    }
}

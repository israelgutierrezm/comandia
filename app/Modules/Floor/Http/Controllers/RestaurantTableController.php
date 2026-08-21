<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Application\JoinTables;
use App\Modules\Shared\Domain\Contracts\LiveServiceProbe;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Http\Requests\JoinTablesRequest;
use App\Modules\Floor\Http\Requests\SaveRestaurantTableRequest;
use App\Modules\Floor\Http\Resources\RestaurantTableResource;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
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
    use AssertsBranchScope;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly JoinTables $joins,

        // El contrato del KERNEL, no un servicio del punto de venta: `Floor` no conoce a `Pos`. Ver `LiveServiceProbe`.
        private readonly LiveServiceProbe $service,
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
     * por error.
     *
     * ## Y liberar se RECHAZA si de verdad hay servicio en curso
     *
     * Es lo que el paso 13 cerró. Sin la comprobación, liberar una mesa con una cuenta abierta encima la deja huérfana:
     * el siguiente cliente se sienta ahí, el mesero abre otra cuenta, y las dos conviven sobre la misma mesa hasta que
     * alguien cobra una y se olvida de la otra.
     *
     * La respuesta la sabe el punto de venta, y este módulo no lo conoce —`Pos` ya depende de `Floor`, así que
     * preguntarlo al revés cerraría un ciclo—. Se pregunta por un contrato del kernel, `LiveServiceProbe`, que `Pos`
     * implementa: la dependencia va invertida y ninguno de los dos módulos conoce al otro.
     */
    public function free(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        if ($restaurantTable->status === TableStatus::Free) {
            throw new ConflictHttpException('Esa mesa ya está libre.');
        }

        if ($this->service->tableHasLiveService((int) $restaurantTable->id)) {
            throw new ConflictHttpException(
                'Esa mesa tiene una cuenta abierta. Cóbrala o cancélala antes de liberarla: liberarla ahora dejaría la '
                .'cuenta huérfana y el siguiente cliente se sentaría encima.',
            );
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

    /**
     * Retirar una mesa del piso.
     *
     * ## Retirar no es borrar, y no puede serlo
     *
     * `pos_accounts.table_id` es `RESTRICT`, y debe serlo: la cuenta de anoche dice en qué mesa se sentó la gente, y
     * eso no se reescribe porque el negocio haya quitado la mesa. Borrar dejaría al negocio eligiendo entre conservar
     * su historial y ordenar su salón.
     *
     * ## Con gente encima, no
     *
     * Se pregunta al POS por el mismo contrato del kernel que usa `free()`: `Floor` no conoce las cuentas. Retirar una
     * mesa ocupada la sacaría del editor mientras alguien sigue comiendo en ella, y la cuenta quedaría colgando de una
     * mesa que la interfaz ya no ofrece.
     *
     * ## Unida, tampoco
     *
     * Una mesa unida forma parte de un conjunto que atiende una cuenta. Retirar la mitad de una mesa de ocho dejaría un
     * conjunto que no se puede deshacer desde ninguna pantalla.
     */
    public function archive(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        $this->assertBranchInScope((int) $restaurantTable->branch_id);

        if ($restaurantTable->isArchived()) {
            throw new ConflictHttpException('Esa mesa ya está retirada del piso.');
        }

        if ($this->service->tableHasLiveService((int) $restaurantTable->id)) {
            throw new ConflictHttpException(
                'Esa mesa tiene una cuenta abierta. Cóbrala antes de retirarla: retirarla ahora la sacaría del editor '
                .'con gente sentada en ella.',
            );
        }

        if ($restaurantTable->isJoined() || $restaurantTable->joinedTables()->exists()) {
            throw new ConflictHttpException(
                'Esa mesa está unida a otra. Sepáralas antes de retirarla.',
            );
        }

        $restaurantTable->archive();

        $this->audit->log(
            action: AuditAction::TABLE_ARCHIVED,
            auditable: $restaurantTable,
            after: ['code' => $restaurantTable->code, 'archived_at' => (string) $restaurantTable->archived_at],
        );

        return new RestaurantTableResource($restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables']));
    }

    /**
     * Devolver una mesa al piso.
     *
     * Vuelve **libre** aunque se hubiera retirado en otro estado: un salón no guarda a medias el servicio de hace tres
     * meses, y devolver una mesa «ocupada» por una cuenta que ya se cobró la dejaría inservible sin que nadie
     * entendiera por qué.
     */
    public function restore(RestaurantTable $restaurantTable): RestaurantTableResource
    {
        $this->assertBranchInScope((int) $restaurantTable->branch_id);

        if (! $restaurantTable->isArchived()) {
            throw new ConflictHttpException('Esa mesa ya está en el piso.');
        }

        $restaurantTable->restore();
        $restaurantTable->update(['status' => TableStatus::Free]);

        $this->audit->log(
            action: AuditAction::TABLE_RESTORED,
            auditable: $restaurantTable,
            after: ['code' => $restaurantTable->code],
        );

        return new RestaurantTableResource($restaurantTable->refresh()->load(['zone', 'joinedTo', 'joinedTables']));
    }
}

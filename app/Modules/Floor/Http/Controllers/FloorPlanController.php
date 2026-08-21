<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Http\Resources\FloorPlanResource;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Planos y zonas del salón (D34).
 *
 * Un plano se crea con sus zonas en la misma petición, y no es azúcar sintáctico: un plano sin zonas no admite mesas, así
 * que crearlo vacío deja al usuario en un callejón —y la pantalla tendría que empujarlo a un segundo formulario para
 * poder hacer nada—. Es la misma lección que el catálogo vacío de motivos de merma (D225).
 */
final class FloorPlanController
{
    use AssertsBranchScope;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, FloorPlan>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name'],
            searchable: ['name'],
            defaultSort: 'name',
            handledByCaller: ['branch'],
        );

        $builder = $query->apply(FloorPlan::query()->with(['branch', 'zones']), $request);

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        return FloorPlanResource::collection($builder->paginate($query->perPage($request)));
    }

    /**
     * Un plano con TODO lo que el editor necesita para dibujarlo: zonas y mesas con su geometría.
     *
     * Es una sola petición a propósito. Con tres —plano, zonas, mesas— el editor tendría que cruzarlas en el cliente y
     * pintaría un plano a medias mientras la tercera viaja, que es exactamente el parpadeo que hace que un editor
     * visual se sienta roto.
     *
     * Incluye las mesas ARCHIVADAS: el editor es donde se restauran, así que ocultarlas ahí las volvería irrecuperables.
     * El piso de venta, que es otra pantalla y otro endpoint, no las trae.
     */
    public function show(FloorPlan $floorPlan): FloorPlanResource
    {
        $this->assertBranchInScope((int) $floorPlan->branch_id);

        return new FloorPlanResource(
            $floorPlan->load(['branch', 'zones', 'tables' => fn ($q) => $q->with(['zone', 'joinedTo'])]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_ulid' => ['required', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:60'],

            // Al menos una zona: un plano sin zonas no admite mesas.
            'zones' => ['required', 'array', 'min:1', 'max:20'],
            'zones.*' => ['required', 'string', 'max:60'],
        ]);

        $branch = Branch::findByUlid($validated['branch_ulid']);

        if ($branch === null) {
            throw new ConflictHttpException('La sucursal indicada no existe.');
        }

        // La sucursal viene del CUERPO. Un plano es el piso de una sucursal: dibujarlo en la ajena crea mesas
        // donde quien las crea no atiende, y de esas mesas cuelgan después las cuentas.
        $this->assertBranchInScope((int) $branch->id);

        $plan = DB::transaction(function () use ($branch, $validated): FloorPlan {
            $plan = FloorPlan::create([
                'branch_id' => $branch->id,
                'name' => $validated['name'],

                // El primer plano de una sucursal es el de omisión. Lo garantiza una columna generada con índice único,
                // así que esto es una comodidad y no la regla: si dos peticiones simultáneas creyeran ser la primera,
                // la base rechazaría la segunda.
                'is_default' => ! FloorPlan::query()->where('branch_id', $branch->id)->exists(),
            ]);

            foreach (array_values($validated['zones']) as $indice => $nombre) {
                FloorZone::create([
                    'floor_plan_id' => $plan->id,
                    'name' => $nombre,
                    'sort_order' => ($indice + 1) * 10,
                ]);
            }

            return $plan;
        });

        $this->audit->log(
            action: AuditAction::FLOOR_PLAN_CREATED,
            auditable: $plan,
            after: ['name' => $plan->name, 'zones' => $validated['zones']],
        );

        return (new FloorPlanResource($plan->refresh()->load(['branch', 'zones'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Marcar un plano como el de omisión de su sucursal.
     *
     * Se hace en transacción y quitando primero el anterior, porque la columna generada con índice único lo impone:
     * poner el nuevo antes de quitar el viejo choca con la restricción. Que la base lo impida es lo que hace que «el
     * plano con el que abre la pantalla» sea una respuesta y no una lotería.
     */
    public function setDefault(FloorPlan $floorPlan): FloorPlanResource
    {
        DB::transaction(function () use ($floorPlan): void {
            FloorPlan::query()
                ->where('branch_id', $floorPlan->branch_id)
                ->where('is_default', true)
                ->whereKeyNot($floorPlan->id)
                ->update(['is_default' => false]);

            $floorPlan->update(['is_default' => true]);
        });

        $this->audit->log(
            action: AuditAction::FLOOR_PLAN_UPDATED,
            auditable: $floorPlan,
            after: ['is_default' => true],
        );

        return new FloorPlanResource($floorPlan->refresh()->load(['branch', 'zones']));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Floor\Http\Requests\SaveFloorLayoutRequest;
use App\Modules\Floor\Http\Resources\FloorPlanResource;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
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
     * Renombrar un plano o cambiar el tamaño del salón.
     *
     * El lienzo también se puede guardar desde el editor junto con las mesas, y no es duplicación: son dos gestos
     * distintos. «El salón mide otra cosa» se decide una vez, en un formulario; «las mesas están así» se arrastra.
     * Obligar a lo primero a pasar por lo segundo haría que renombrar un plano exigiera mandar sus doce mesas.
     */
    public function update(Request $request, FloorPlan $floorPlan): FloorPlanResource
    {
        $this->assertBranchInScope((int) $floorPlan->branch_id);

        $validado = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'canvas_width' => ['sometimes', 'numeric', 'min:100', 'max:99999.99', 'decimal:0,2'],
            'canvas_height' => ['sometimes', 'numeric', 'min:100', 'max:99999.99', 'decimal:0,2'],
        ]);

        $antes = $floorPlan->only(['name', 'canvas_width', 'canvas_height']);

        $floorPlan->update($validado);

        $this->audit->log(
            action: AuditAction::FLOOR_PLAN_UPDATED,
            auditable: $floorPlan,
            before: $antes,
            after: $floorPlan->only(['name', 'canvas_width', 'canvas_height']),
        );

        return new FloorPlanResource($floorPlan->refresh()->load(['branch', 'zones']));
    }

    /**
     * El salón entero, en una sola escritura (§1.2 del diseño).
     *
     * ## El 409 devuelve el plano ACTUAL, no sólo un mensaje
     *
     * Que dos gerentes se pisen es normal en un negocio con más de un turno. Lo que no puede pasar es que quien pierde
     * la carrera se quede sin saber qué había: con sólo un mensaje tendría que recargar a ciegas y volver a arrastrar
     * doce mesas de memoria. Con el plano actual en la respuesta, la pantalla puede enseñar lo que hay y ofrecer
     * reaplicar.
     *
     * ## Todo o nada, y las mesas tienen que ser DE ESTE plano
     *
     * Una mesa de otro plano colada en el lote se movería a coordenadas de un salón que no es el suyo, y como los dos
     * planos son del mismo negocio ninguna comprobación de tenant lo vería. Se valida contra las zonas del plano, que
     * es lo que ata una mesa a un salón.
     *
     * ## Un solo asiento de auditoría
     *
     * Mover doce mesas es **un** acto. Doce asientos idénticos con distinto ULID harían ilegible la bitácora del día
     * que alguien reacomodó el salón, que es justo el día que alguien querría leerla.
     */
    public function saveLayout(SaveFloorLayoutRequest $request, FloorPlan $floorPlan): JsonResponse
    {
        $this->assertBranchInScope((int) $floorPlan->branch_id);

        if ((int) $request->integer('version') !== (int) $floorPlan->version) {
            return new JsonResponse([
                'type' => 'version_conflict',
                'title' => 'Alguien más guardó este plano mientras lo tenías abierto. Revisa lo que hay antes de '
                    .'volver a guardar.',
                'status' => 409,
                'current_version' => (int) $floorPlan->version,
                'data' => (new FloorPlanResource(
                    $floorPlan->load(['branch', 'zones', 'tables' => fn ($q) => $q->with(['zone', 'joinedTo'])]),
                ))->toArray($request),
            ], 409);
        }

        $zonas = FloorZone::query()->where('floor_plan_id', $floorPlan->id)->pluck('id', 'ulid');

        $mesas = RestaurantTable::query()
            ->whereIn('floor_zone_id', $zonas->values())
            ->get()
            ->keyBy('ulid');

        $entrada = collect($request->validated('tables'));

        $ajenas = $entrada->pluck('ulid')->reject(fn (string $ulid): bool => $mesas->has($ulid));

        if ($ajenas->isNotEmpty()) {
            throw new ConflictHttpException(sprintf(
                'Estas mesas no son de este plano: %s',
                $ajenas->implode(', '),
            ));
        }

        DB::transaction(function () use ($floorPlan, $request, $entrada, $mesas, $zonas): void {
            if ($request->has('canvas')) {
                $floorPlan->canvas_width = (string) $request->input('canvas.width');
                $floorPlan->canvas_height = (string) $request->input('canvas.height');
            }

            foreach ($entrada as $fila) {
                $mesa = $mesas->get($fila['ulid']);

                $mesa->x = $fila['x'];
                $mesa->y = $fila['y'];
                $mesa->width = $fila['width'];
                $mesa->height = $fila['height'];
                $mesa->rotation = $fila['rotation'];
                $mesa->shape = $fila['shape'];

                // Cambiar de zona es mover la mesa de terraza a salón, y se hace aquí porque en el editor es el mismo
                // gesto: se arrastra y se suelta en otra zona. Una zona de otro plano ni siquiera está en el mapa.
                if (isset($fila['zone_ulid']) && $zonas->has($fila['zone_ulid'])) {
                    $mesa->floor_zone_id = $zonas->get($fila['zone_ulid']);
                }

                $mesa->save();
            }

            // La versión sube UNA vez por guardado, no una por mesa: es la versión del plano, no de cada figura.
            $floorPlan->version = (int) $floorPlan->version + 1;
            $floorPlan->save();
        });

        $this->audit->log(
            action: AuditAction::FLOOR_LAYOUT_SAVED,
            auditable: $floorPlan,
            after: [
                'plan' => $floorPlan->name,
                'tables' => $entrada->count(),
                'version' => (int) $floorPlan->version,
            ],
        );

        return new JsonResponse([
            'data' => (new FloorPlanResource(
                $floorPlan->refresh()->load(['branch', 'zones', 'tables' => fn ($q) => $q->with(['zone', 'joinedTo'])]),
            ))->toArray($request),
        ]);
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

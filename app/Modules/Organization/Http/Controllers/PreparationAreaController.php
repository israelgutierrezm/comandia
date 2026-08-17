<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Http\Requests\StorePreparationAreaRequest;
use App\Modules\Organization\Http\Requests\UpdatePreparationAreaRequest;
use App\Modules\Organization\Http\Resources\PreparationAreaResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Administración de áreas de preparación (§3).
 */
final class PreparationAreaController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PreparationArea>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['sort_order', 'name', 'code'],
            searchable: ['name', 'code'],
            // Por `sort_order` y no por nombre: el orden de las áreas es una decisión del
            // tenant —la cocina antes que los postres— y la lista debe respetarla por defecto.
            defaultSort: 'sort_order',
        );

        $areas = $query
            ->apply(PreparationArea::query()->with(['branch', 'warehouse']), $request)
            ->paginate($query->perPage($request));

        return PreparationAreaResource::collection($areas);
    }

    public function store(StorePreparationAreaRequest $request): JsonResponse
    {
        $area = PreparationArea::create([
            'branch_id' => Branch::findByUlid($request->string('branch_ulid')->toString())?->id,
            'warehouse_id' => Warehouse::findByUlid($request->string('warehouse_ulid')->toString())?->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'sort_order' => $request->integer('sort_order'),
        ]);

        $this->audit->log(
            action: AuditAction::PREPARATION_AREA_CREATED,
            auditable: $area,
            after: $area->only(['code', 'name', 'branch_id', 'warehouse_id', 'status']),
        );

        return (new PreparationAreaResource($area->load(['branch', 'warehouse'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PreparationArea $preparationArea): PreparationAreaResource
    {
        return new PreparationAreaResource($preparationArea->load(['branch', 'warehouse']));
    }

    public function update(
        UpdatePreparationAreaRequest $request,
        PreparationArea $preparationArea,
    ): PreparationAreaResource {
        // Cambiar de almacén es la operación con consecuencia de inventario, así que el
        // antes/después la incluye siempre: es lo que un auditor querrá ver si las
        // existencias de un almacén dejan de cuadrar a partir de una fecha.
        $before = $preparationArea->only(['name', 'sort_order', 'warehouse_id']);

        $data = $request->safe()->except('warehouse_ulid');

        if ($request->has('warehouse_ulid')) {
            $data['warehouse_id'] = Warehouse::findByUlid(
                $request->string('warehouse_ulid')->toString()
            )?->id;
        }

        $preparationArea->update($data);

        $this->audit->log(
            action: AuditAction::PREPARATION_AREA_UPDATED,
            auditable: $preparationArea,
            before: $before,
            after: $preparationArea->only(['name', 'sort_order', 'warehouse_id']),
        );

        return new PreparationAreaResource($preparationArea->refresh()->load(['branch', 'warehouse']));
    }

    /**
     * Baja de área: cambio de estado, no borrado (D80). Hay comandas históricas ruteadas aquí.
     */
    public function archive(PreparationArea $preparationArea): PreparationAreaResource
    {
        $before = ['status' => $preparationArea->status->value];

        $preparationArea->update(['status' => OperationalStatus::Inactive]);

        $this->audit->log(
            action: AuditAction::PREPARATION_AREA_UPDATED,
            auditable: $preparationArea,
            before: $before,
            after: ['status' => OperationalStatus::Inactive->value],
        );

        return new PreparationAreaResource($preparationArea->refresh()->load(['branch', 'warehouse']));
    }
}

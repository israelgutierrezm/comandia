<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Http\Requests\StoreWarehouseRequest;
use App\Modules\Organization\Http\Requests\UpdateWarehouseRequest;
use App\Modules\Organization\Http\Resources\WarehouseResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Administración de almacenes (D11).
 */
final class WarehouseController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Warehouse>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            // `branch` filtra por ULID y no por id interno: la API no expone PKs (§7).
            filters: ['status' => 'status', 'kind' => 'kind'],
            sortable: ['name', 'code', 'created_at'],
            searchable: ['name', 'code'],
            defaultSort: 'name',
        );

        $warehouses = $query
            ->apply(Warehouse::query()->with('branch'), $request)
            ->paginate($query->perPage($request));

        return WarehouseResource::collection($warehouses);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $branchId = null;

        if ($request->filled('branch_ulid')) {
            $branchId = Branch::findByUlid($request->string('branch_ulid')->toString())?->id;
        }

        $warehouse = Warehouse::create([
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'kind' => $request->string('kind')->toString(),
            'branch_id' => $branchId,
        ]);

        $this->audit->log(
            action: AuditAction::WAREHOUSE_CREATED,
            auditable: $warehouse,
            after: $warehouse->only(['code', 'name', 'kind', 'branch_id', 'status']),
        );

        return (new WarehouseResource($warehouse->load('branch')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($warehouse->load('branch'));
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        $before = $warehouse->only(['name']);

        $warehouse->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::WAREHOUSE_UPDATED,
            auditable: $warehouse,
            before: $before,
            after: $warehouse->only(['name']),
        );

        return new WarehouseResource($warehouse->refresh()->load('branch'));
    }

    /**
     * Baja de almacén: cambio de estado, no borrado (D80).
     *
     * Se rechaza si un área de preparación activa consume de él. La FK ya es `restrictOnDelete`
     * para el borrado, pero desactivarlo sin más dejaría al área descontando de un almacén
     * inactivo — y el descuento por receta corre en la cola `critical`, así que el fallo
     * aparecería como una existencia incorrecta y no como un error visible.
     */
    public function archive(Warehouse $warehouse): WarehouseResource
    {
        $areasActivas = $warehouse->preparationAreas()
            ->where('status', OperationalStatus::Active->value)
            ->count();

        if ($areasActivas > 0) {
            throw new ConflictHttpException(sprintf(
                'No se puede dar de baja este almacén: %d área(s) de preparación activa(s) '
                .'descuentan de él. Reconfigúralas primero.',
                $areasActivas,
            ));
        }

        $before = ['status' => $warehouse->status->value];

        $warehouse->update(['status' => OperationalStatus::Inactive]);

        $this->audit->log(
            action: AuditAction::WAREHOUSE_UPDATED,
            auditable: $warehouse,
            before: $before,
            after: ['status' => OperationalStatus::Inactive->value],
        );

        return new WarehouseResource($warehouse->refresh()->load('branch'));
    }
}

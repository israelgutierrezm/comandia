<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Http\Requests\StoreBranchRequest;
use App\Modules\Organization\Http\Requests\UpdateBranchRequest;
use App\Modules\Organization\Http\Resources\BranchResource;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Administración de sucursales.
 *
 * La autorización la aplica el middleware de la ruta (`can` / `can.write`), que pasa por el
 * servicio de contexto y evalúa el ROL ACTIVO (D9). El controlador no vuelve a verificar: dos
 * verificaciones son dos sitios donde una puede quedarse desactualizada.
 *
 * El global scope de tenant hace innecesario filtrar por tenant en cada consulta, y el binding
 * de ruta por ULID hace que un identificador ajeno devuelva 404 en lugar de 403 — que es lo
 * correcto: no se confirma la existencia de un recurso de otro negocio.
 */
final class BranchController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Branch>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // Whitelist explícita: lo que no está declarado no se puede filtrar ni ordenar (§8).
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name', 'code', 'created_at'],
            searchable: ['name', 'code', 'municipality'],
            defaultSort: 'name',
        );

        // Paginación por página y no por cursor: las sucursales son un catálogo pequeño y el
        // usuario espera poder saltar a la página 3. El cursor se reserva para listados
        // transaccionales de alto volumen (§8).
        $branches = $query
            ->apply(Branch::query()->with('defaultWarehouse'), $request)
            ->paginate($query->perPage($request));

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->safe()->except('code') + [
            'code' => $request->string('code')->toString(),
        ]);

        $this->audit->log(
            action: AuditAction::BRANCH_CREATED,
            auditable: $branch,
            after: $branch->only(['code', 'name', 'status', 'timezone']),
        );

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch->load('defaultWarehouse'));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        // El antes/después de la bitácora se toma de los atributos que de verdad cambiaron:
        // registrar el objeto completo llenaría la auditoría de ruido y haría más difícil ver
        // qué se modificó, que es justo lo que se consulta.
        $before = $branch->only(['name', 'timezone', 'default_warehouse_id']);

        $data = $request->safe()->except('default_warehouse_ulid');

        if ($request->has('default_warehouse_ulid')) {
            $ulid = $request->string('default_warehouse_ulid')->toString();

            $data['default_warehouse_id'] = $ulid === ''
                ? null
                : Warehouse::findByUlid($ulid)?->id;
        }

        $branch->update($data);

        $this->audit->log(
            action: AuditAction::BRANCH_UPDATED,
            auditable: $branch,
            before: $before,
            after: $branch->only(['name', 'timezone', 'default_warehouse_id']),
        );

        return new BranchResource($branch->refresh()->load('defaultWarehouse'));
    }

    /**
     * Baja de sucursal: cambio de estado, no borrado (D80).
     *
     * Hay ventas, cortes y folios apuntando a esta sucursal. Borrarla —aun blandamente—
     * rompería la trazabilidad de documentos que el negocio necesita conservar. `inactive`
     * significa "no se puede operar aquí desde hoy", no "no existió".
     */
    public function archive(Branch $branch): BranchResource
    {
        $before = ['status' => $branch->status->value];

        $branch->update(['status' => OperationalStatus::Inactive]);

        $this->audit->log(
            action: AuditAction::BRANCH_UPDATED,
            auditable: $branch,
            before: $before,
            after: ['status' => OperationalStatus::Inactive->value],
        );

        return new BranchResource($branch->refresh());
    }
}

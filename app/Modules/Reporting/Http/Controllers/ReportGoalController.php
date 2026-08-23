<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Http\Requests\StoreGoalRequest;
use App\Modules\Reporting\Http\Resources\ReportGoalResource;
use App\Modules\Reporting\Infrastructure\Models\ReportGoal;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Metas de reporte (Tanda C, D46). Administrarlas exige `dashboards.goals.manage`.
 */
final class ReportGoalController
{
    use AssertsBranchScope;

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, ReportGoal>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = ReportGoal::query()
            ->when($request->filled('report'), fn ($q) => $q->where('report_key', (string) $request->string('report')))
            ->get();

        return ReportGoalResource::collection($goals);
    }

    public function store(StoreGoalRequest $request): JsonResponse
    {
        // El ULID de sucursal se traduce a id DENTRO del tenant; null = meta consolidada.
        $branchId = $request->filled('branch_ulid')
            ? Branch::query()->where('ulid', (string) $request->string('branch_ulid'))->value('id')
            : null;

        // Una sucursal enviada que no existe en el negocio no se acepta como «consolidada» por descuido.
        if ($request->filled('branch_ulid') && $branchId === null) {
            abort(422, 'La sucursal indicada no existe.');
        }

        // Y si existe, tiene que estar en el ALCANCE del rol activo: el tenant_id no basta —una sucursal ajena es del
        // mismo negocio y llega como un modelo válido— (candado BranchScopeIsAsserted).
        $this->assertBranchInScope($branchId === null ? null : (int) $branchId);

        // updateOrCreate por el alcance: fijar la meta dos veces la ajusta, no la duplica.
        $goal = ReportGoal::updateOrCreate(
            [
                'report_key' => (string) $request->string('report_key'),
                'measure_key' => (string) $request->string('measure_key'),
                'branch_id' => $branchId,
                'period' => (string) $request->string('period'),
            ],
            [
                'target_value' => (string) $request->string('target_value'),
                'direction' => (string) $request->string('direction'),
            ],
        );

        return new JsonResponse(['data' => new ReportGoalResource($goal)], $goal->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(ReportGoal $reportGoal): JsonResponse
    {
        $reportGoal->delete();

        return new JsonResponse(status: 204);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Http\Requests\StoreGoalRequest;
use App\Modules\Reporting\Http\Resources\ReportGoalResource;
use App\Modules\Reporting\Infrastructure\Models\ReportGoal;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Metas de reporte (Tanda C, D46). Administrarlas exige `dashboards.goals.manage`.
 *
 * ## El alcance por sucursal, en las tres puertas
 *
 * Una meta de sucursal sólo la ve, la fija y la borra quien opera en ESA sucursal; una consolidada (sin sucursal) no
 * pertenece a ninguna y la administra cualquiera con el permiso. El `tenant_id` no basta: la sucursal ajena es del mismo
 * negocio y pasa el global scope entera. Antes sólo `store` lo comprobaba, y un rol acotado a una sucursal listaba y
 * borraba las metas de las demás.
 */
final class ReportGoalController
{
    use AssertsBranchScope;

    public function __construct(private readonly ContextHolder $context) {}

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, ReportGoal>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = ReportGoal::query()
            ->when($request->filled('report'), fn ($q) => $q->where('report_key', (string) $request->string('report')))
            // El criterio de `store`: las consolidadas, más las de las sucursales que alcanza la membresía.
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $this->scopedBranchIds()))
            ->get();

        return ReportGoalResource::collection($goals);
    }

    public function store(StoreGoalRequest $request): JsonResponse
    {
        // El ULID de sucursal se traduce a id DENTRO del tenant; null = meta consolidada.
        $branchId = $request->filled('branch_ulid')
            ? Branch::query()->where('ulid', (string) $request->string('branch_ulid'))->value('id')
            : null;

        // Una sucursal enviada que no existe en el negocio no se acepta como «consolidada» por descuido. Como error del
        // campo, no un 422 suelto: la pantalla lo pinta junto al selector de sucursal.
        if ($request->filled('branch_ulid') && $branchId === null) {
            throw ValidationException::withMessages(['branch_ulid' => 'La sucursal indicada no existe.']);
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

        // Releída: sin esto la meta recién creada salía con el valor tal como se tecleó («100») y en el listado con la
        // escala de la columna («100.0000»), dos formas del mismo dato.
        return new JsonResponse(
            ['data' => new ReportGoalResource($goal->refresh())],
            $goal->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(ReportGoal $reportGoal): JsonResponse
    {
        // Como al fijarla: la meta de una sucursal exige alcance a esa sucursal. Una consolidada (null) no pertenece a
        // ninguna, y el guardián la deja pasar — el mismo criterio que `store`.
        $this->assertBranchInScope($reportGoal->branch_id === null ? null : (int) $reportGoal->branch_id);

        $reportGoal->delete();

        return new JsonResponse(status: 204);
    }

    /**
     * Las sucursales en que opera la membresía —las de su alcance, o todas las activas si `has_all_branches`—: la misma
     * fuente que consulta `assertBranchInScope`. Sin membresía, ninguna, y sólo se listan las consolidadas.
     *
     * @return list<int>
     */
    private function scopedBranchIds(): array
    {
        return $this->context->getOrNull()?->membership?->scopedBranchIds() ?? [];
    }
}

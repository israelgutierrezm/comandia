<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\EvaluateGoal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El estado del semáforo de una medida contra su meta (Tanda C, D46).
 *
 * Corre el reporte (gran total) y lo compara con la meta consolidada del periodo. El permiso lo verifica el motor por el
 * reporte (como el resto del motor); por eso la ruta es excepción declarada en `RoutePermissionTest`. La comparación vive
 * en el servidor, no en Vue.
 */
final class GoalStatusController
{
    public function __construct(private readonly EvaluateGoal $evaluator) {}

    public function __invoke(Request $request, string $report): JsonResponse
    {
        $status = $this->evaluator->evaluate(
            $report,
            (string) $request->string('measure'),
            // El resto son los parámetros del reporte (rango de fechas, etc.); `measure` y `period` no lo son.
            $request->except(['measure', 'period']),
            // v1: el semáforo usa la meta CONSOLIDADA; las metas por sucursal se guardan pero se usan en una afinación
            // posterior.
            branchId: null,
            period: (string) $request->string('period', 'month'),
        );

        return new JsonResponse(['data' => $status]);
    }
}

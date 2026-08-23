<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Infrastructure\Models\ReportGoal;

/**
 * El semáforo: compara el valor REAL de una medida (gran total del reporte) contra su meta (D46).
 *
 * El valor lo da el motor —una sola fila agregada, con el mismo scoping de tenant y sucursal del rol activo—; la meta se
 * busca por (reporte, medida, sucursal, periodo). El estado (en meta / cerca / lejos) se decide en el SERVIDOR con
 * bcmath, no en Vue: es lógica de negocio (D134, «sin lógica crítica en el frontend»).
 *
 * La tolerancia del amarillo es del 10% en v1 —una constante—; el diseño la prevé como toggle jerárquico configurable
 * (precedente `pricing.stale_price_tolerance_percent`), que queda como afinación pendiente.
 */
final readonly class EvaluateGoal
{
    private const TOLERANCE_PERCENT = '10';

    public function __construct(private RunReport $runner) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{value: string, target: ?string, direction: ?string, status: string}
     */
    public function evaluate(string $reportKey, string $measureKey, array $params, ?int $branchId, string $period): array
    {
        // Gran total del reporte para leer la medida como un solo número (el motor verifica el permiso y aplica el scope).
        $params['group_by'] = '';
        $result = $this->runner->run($reportKey, $params);

        $value = (string) ($result['rows'][0][$measureKey] ?? '0');

        $goal = ReportGoal::query()
            ->where('report_key', $reportKey)
            ->where('measure_key', $measureKey)
            ->where('branch_id', $branchId)
            ->where('period', $period)
            ->first();

        if ($goal === null) {
            return ['value' => $value, 'target' => null, 'direction' => null, 'status' => 'no_goal'];
        }

        return [
            'value' => $value,
            'target' => (string) $goal->target_value,
            'direction' => $goal->direction,
            'status' => $this->status($value, (string) $goal->target_value, $goal->direction),
        ];
    }

    private function status(string $value, string $target, string $direction): string
    {
        $tolerance = bcmul($target, bcdiv(self::TOLERANCE_PERCENT, '100', 6), 4);

        if ($direction === 'higher_better') {
            if (bccomp($value, $target, 4) >= 0) {
                return 'on_track';
            }

            return bccomp($value, bcsub($target, $tolerance, 4), 4) >= 0 ? 'warning' : 'off_track';
        }

        // lower_better (mermas, gastos): estar POR DEBAJO de la meta es bueno.
        if (bccomp($value, $target, 4) <= 0) {
            return 'on_track';
        }

        return bccomp($value, bcadd($target, $tolerance, 4), 4) <= 0 ? 'warning' : 'off_track';
    }
}

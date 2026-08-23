<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Infrastructure\Models\ReportGoal;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Carbon\CarbonImmutable;

/**
 * El semáforo: compara el valor REAL de una medida (gran total del reporte, EN EL PERIODO) contra su meta (D46).
 *
 * El valor lo da el motor —una sola fila agregada, con el scoping de tenant y sucursal del rol activo—, acotado al
 * periodo de la meta (el mes en curso para una meta mensual, etc.): comparar el acumulado de todo el tiempo contra una
 * meta mensual no significaría nada. La meta se busca por (reporte, medida, sucursal, periodo). El estado (en meta / cerca
 * / lejos) se decide en el SERVIDOR con bcmath (D134), no en Vue.
 *
 * La tolerancia del amarillo es del 10% en v1 —constante—; el diseño la prevé como toggle jerárquico configurable, que
 * queda como afinación pendiente.
 */
final readonly class EvaluateGoal
{
    private const TOLERANCE_PERCENT = '10';

    public function __construct(
        private RunReport $runner,
        private ReportRegistry $registry,
        private ContextHolder $context,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{value: string, target: ?string, direction: ?string, status: string}
     */
    public function evaluate(string $reportKey, string $measureKey, array $params, ?int $branchId, string $period): array
    {
        $params['group_by'] = '';
        $params = $this->withPeriodRange($reportKey, $params, $period);

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

    /**
     * Añade el rango del periodo en curso al filtro de fecha del reporte, salvo que el cliente ya haya mandado uno. Si el
     * reporte no tiene filtro de fecha, no toca nada.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withPeriodRange(string $reportKey, array $params, string $period): array
    {
        $definition = $this->registry->get($reportKey);

        if ($definition === null) {
            return $params;
        }

        foreach ($definition->filters() as $filter) {
            if ($filter->operator !== 'date_range') {
                continue;
            }

            if (array_key_exists($filter->key.'_from', $params)) {
                return $params; // el cliente ya acotó el rango: se respeta.
            }

            [$from, $to] = $this->periodRange($period);
            $params[$filter->key.'_from'] = $from;
            $params[$filter->key.'_to'] = $to;

            return $params;
        }

        return $params;
    }

    /**
     * El rango del periodo EN CURSO (del inicio del periodo a hoy), en fechas locales de la sucursal.
     *
     * @return array{0: string, 1: string}
     */
    private function periodRange(string $period): array
    {
        $timezone = (string) ($this->context->get()->activeBranch?->timezone ?? 'UTC');
        $now = CarbonImmutable::now($timezone);

        $start = match ($period) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(),
            'year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };

        return [$start->toDateString(), $now->toDateString()];
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

        if (bccomp($value, $target, 4) <= 0) {
            return 'on_track';
        }

        return bccomp($value, bcadd($target, $tolerance, 4), 4) <= 0 ? 'warning' : 'off_track';
    }
}

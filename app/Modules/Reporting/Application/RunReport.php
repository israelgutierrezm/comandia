<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\Exceptions\ReportException;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Reporting\ReportDefinition;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El motor: ejecuta una definición de reporte con los parámetros pedidos (ADR-006, ADR-007).
 *
 * Un solo camino de ejecución, auditado una vez: verifica el permiso, inyecta el scoping de tenant (por el global scope
 * de Eloquent) y de sucursal (del alcance del rol activo), valida los parámetros contra la whitelist de la definición,
 * arma el `SELECT` agregado + `GROUP BY`, ordena de forma determinista y ejecuta. La definición nunca aplica el scoping:
 * es la garantía de aislamiento de ADR-006 regla 4.
 */
final readonly class RunReport
{
    private const MAX_ROWS = 500;

    public function __construct(
        private ReportRegistry $registry,
        private ContextHolder $context,
        private Authorize $authorize,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array{report: string, grouping: list<string>, columns: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function run(string $key, array $params): array
    {
        $definition = $this->registry->get($key) ?? throw ReportException::notFound($key);

        // El permiso del reporte, con el rol activo (D9). Lanza 403 si no.
        $this->authorize->authorize($definition->permission());

        $grouping = $this->resolveGrouping($definition, $params);
        $this->rejectUnknownParams($definition, $params);

        $query = $definition->baseQuery();
        $this->applyBranchScope($definition, $query);
        $this->applyFilters($definition, $query, $params);
        $this->applyAggregation($definition, $query, $grouping);

        $rows = $query->limit(self::MAX_ROWS)->get()
            ->map(static fn ($model): array => $model->getAttributes())
            ->all();

        return [
            'report' => $definition->key(),
            'grouping' => $grouping,
            'columns' => $this->columnsMeta($definition, $grouping),
            'rows' => $rows,
        ];
    }

    /**
     * La agrupación pedida (o la de omisión), validada contra la whitelist.
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private function resolveGrouping(ReportDefinition $definition, array $params): array
    {
        // Ausente = agrupación por omisión. PRESENTE pero vacío = gran total (una sola fila agregada), lo que piden los
        // widgets de número y semáforo. Con valores = esas dimensiones.
        if (! array_key_exists('group_by', $params)) {
            return $definition->defaultGrouping();
        }

        $requested = $params['group_by'];

        // Vacío = gran total. El cliente HTTP omite los parámetros de cadena vacía a propósito (un filtro vacío no es un
        // filtro), así que un widget de número/semáforo no puede mandar `group_by=`: usa el centinela `__total__`, que
        // sobrevive la serialización y significa lo mismo.
        if ($requested === '' || $requested === [] || $requested === '__total__') {
            return [];
        }

        $keys = array_values(array_filter(
            is_array($requested) ? $requested : explode(',', (string) $requested),
            static fn ($k): bool => $k !== '',
        ));

        foreach ($keys as $key) {
            if (! in_array($key, $definition->groupings(), true)) {
                throw ReportException::invalidGrouping((string) $key);
            }
        }

        return $keys;
    }

    /**
     * Rechaza cualquier parámetro que no sea reservado (group_by, limit) ni un filtro declarado.
     *
     * @param  array<string, mixed>  $params
     */
    private function rejectUnknownParams(ReportDefinition $definition, array $params): void
    {
        $allowed = ['group_by', 'limit'];

        foreach ($definition->filters() as $filter) {
            if ($filter->operator === 'date_range') {
                $allowed[] = $filter->key.'_from';
                $allowed[] = $filter->key.'_to';
            } else {
                $allowed[] = $filter->key;
            }
        }

        $unknown = array_values(array_diff(array_keys($params), $allowed));

        if ($unknown !== []) {
            throw ReportException::unknownParameters($unknown);
        }
    }

    /**
     * El alcance de sucursal del rol activo. Nunca lo declara la definición (ADR-006 regla 4): lo pone el motor.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyBranchScope(ReportDefinition $definition, Builder $query): void
    {
        $branchIds = $this->context->get()->requireMembership()->scopedBranchIds();

        $query->whereIn($definition->branchColumn(), $branchIds);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $params
     */
    private function applyFilters(ReportDefinition $definition, Builder $query, array $params): void
    {
        foreach ($definition->filters() as $filter) {
            if ($filter->operator === 'date_range') {
                $from = $params[$filter->key.'_from'] ?? null;
                $to = $params[$filter->key.'_to'] ?? null;

                if ($from !== null && $from !== '') {
                    $query->where($filter->column, '>=', $this->localDayStartUtc((string) $from));
                }

                if ($to !== null && $to !== '') {
                    $query->where($filter->column, '<=', $this->localDayEndUtc((string) $to));
                }

                continue;
            }

            if (! array_key_exists($filter->key, $params)) {
                continue;
            }

            $value = $params[$filter->key];

            if ($filter->operator === 'in') {
                $ids = array_map(fn ($v) => $this->translate($filter, (string) $v), (array) $value);
                $query->whereIn($filter->column, $ids);

                continue;
            }

            $query->where($filter->column, $this->translate($filter, (string) $value));
        }
    }

    /**
     * Traduce un valor de filtro a lo que la columna guarda. Un ULID se resuelve a id interno DENTRO del tenant; si no
     * existe, devuelve `-1` (no casa con nada) en vez de fugar filas de otro negocio.
     */
    private function translate(\App\Modules\Shared\Domain\Reporting\FilterSpec $filter, string $value): int|string
    {
        if ($filter->type !== 'ulid') {
            return $filter->type === 'int' ? (int) $value : $value;
        }

        $tenantId = (int) $this->context->get()->requireMembership()->tenant_id;

        $id = DB::table((string) $filter->ulidTable)
            ->where('tenant_id', $tenantId)
            ->where('ulid', $value)
            ->value('id');

        return $id === null ? -1 : (int) $id;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $grouping
     */
    private function applyAggregation(ReportDefinition $definition, Builder $query, array $grouping): void
    {
        $dimensions = $definition->dimensions();
        $measures = $definition->measures();

        foreach ($grouping as $key) {
            $dimension = $dimensions[$key];
            $query->selectRaw("{$dimension->expression} as `{$dimension->key}`");
            $query->groupByRaw($dimension->expression);
        }

        foreach ($measures as $measure) {
            $query->selectRaw("{$measure->expression} as `{$measure->key}`");
        }

        // Orden determinista: por la primera medida de mayor a menor, con desempate por la primera dimensión.
        $firstMeasure = $measures[array_key_first($measures)];
        $query->orderByRaw("{$firstMeasure->expression} desc");

        if ($grouping !== []) {
            $query->orderByRaw($dimensions[$grouping[0]]->expression.' asc');
        }
    }

    /**
     * @param  list<string>  $grouping
     * @return array<string, mixed>
     */
    private function columnsMeta(ReportDefinition $definition, array $grouping): array
    {
        $dimensions = [];

        foreach ($grouping as $key) {
            $dimension = $definition->dimensions()[$key];
            $dimensions[] = ['key' => $dimension->key, 'label' => $dimension->label];
        }

        $measures = [];

        foreach ($definition->measures() as $measure) {
            $measures[] = ['key' => $measure->key, 'label' => $measure->label, 'format' => $measure->format];
        }

        return ['dimensions' => $dimensions, 'measures' => $measures];
    }

    private function localDayStartUtc(string $date): string
    {
        return CarbonImmutable::parse($date.' 00:00:00', $this->branchTimezone())
            ->utc()
            ->toDateTimeString();
    }

    private function localDayEndUtc(string $date): string
    {
        return CarbonImmutable::parse($date.' 23:59:59', $this->branchTimezone())
            ->utc()
            ->toDateTimeString();
    }

    /**
     * La zona de la sucursal activa para interpretar el rango de fechas (§7). Si no hay sucursal activa —un rol con todas
     * las sucursales—, se cae a UTC: el rango es coarse y el aislamiento no depende de esto.
     */
    private function branchTimezone(): string
    {
        return (string) ($this->context->get()->activeBranch?->timezone ?? 'UTC');
    }
}

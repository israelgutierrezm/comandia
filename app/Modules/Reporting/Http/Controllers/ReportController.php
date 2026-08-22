<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\RunReport;
use App\Modules\Reporting\Domain\Exceptions\ReportException;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Domain\Reporting\ReportDefinition;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El endpoint genérico del motor de reportes (ADR-006).
 *
 * ## Por qué estas rutas no llevan permiso en el middleware
 *
 * El permiso NO es fijo por ruta: cada reporte declara el suyo (ADR-006 regla 3) y se verifica en el motor/definición.
 * Un `can:` fijo sería mentira —el permiso depende de qué reporte se pide—. Es la misma razón por la que
 * `broadcasting/auth` no declara permiso: el candado `RoutePermissionTest` las lista como excepción justificada.
 */
final class ReportController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly RunReport $runner,
        private readonly Authorize $authorize,
    ) {}

    /**
     * El catálogo de reportes que el rol activo PUEDE ver (para el menú). No filtra datos: sólo nombres.
     */
    public function index(): JsonResponse
    {
        $reports = array_values(array_filter(
            array_map(
                fn (ReportDefinition $d): array => ['key' => $d->key(), 'label' => $d->label()],
                array_filter($this->registry->all(), fn (ReportDefinition $d): bool => $this->authorize->allows($d->permission())),
            ),
        ));

        return new JsonResponse(['data' => $reports]);
    }

    /**
     * La definición de un reporte —dimensiones, medidas, filtros, agrupaciones— con la que el frontend se autoconfigura.
     */
    public function definition(string $report): JsonResponse
    {
        $definition = $this->registry->get($report) ?? throw ReportException::notFound($report);

        $this->authorize->authorize($definition->permission());

        return new JsonResponse(['data' => [
            'key' => $definition->key(),
            'label' => $definition->label(),
            'dimensions' => array_map(
                static fn ($d): array => ['key' => $d->key, 'label' => $d->label],
                array_values($definition->dimensions()),
            ),
            'measures' => array_map(
                static fn ($m): array => ['key' => $m->key, 'label' => $m->label, 'format' => $m->format],
                array_values($definition->measures()),
            ),
            'filters' => array_map(
                static fn ($f): array => ['key' => $f->key, 'operator' => $f->operator, 'type' => $f->type],
                array_values($definition->filters()),
            ),
            'groupings' => $definition->groupings(),
            'default_grouping' => $definition->defaultGrouping(),
        ]]);
    }

    /**
     * Ejecuta el reporte con los parámetros de la query (validados contra la whitelist por el motor).
     */
    public function show(Request $request, string $report): JsonResponse
    {
        return new JsonResponse(['data' => $this->runner->run($report, $request->query())]);
    }
}

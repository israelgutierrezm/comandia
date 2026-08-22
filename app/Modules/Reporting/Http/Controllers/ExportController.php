<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Domain\Exceptions\ReportException;
use App\Modules\Reporting\Http\Requests\StoreExportRequest;
use App\Modules\Reporting\Http\Resources\ReportExportResource;
use App\Modules\Reporting\Infrastructure\Models\ReportExport;
use App\Modules\Reporting\Jobs\GenerateReportExport;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportaciones de reportes (Tanda B, ADR-006 regla 5).
 *
 * El export pesado NO corre en la petición: se encola en `exports` y esta superficie deja pedirlo, consultar su estado y
 * descargarlo cuando esté listo. El permiso de CREAR un export es el del reporte (in-code, como el motor); consultar y
 * descargar están acotados al AUTOR del export. Por eso estas rutas son excepción declarada en `RoutePermissionTest`.
 */
final class ExportController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly Authorize $authorize,
        private readonly ContextHolder $context,
    ) {}

    /**
     * Pide un export: verifica el permiso del reporte, crea la fila `pending` y encola el trabajo.
     */
    public function store(StoreExportRequest $request, string $report): JsonResponse
    {
        $definition = $this->registry->get($report) ?? throw ReportException::notFound($report);
        $this->authorize->authorize($definition->permission());

        $membership = $this->context->get()->requireMembership();

        $export = ReportExport::create([
            'membership_id' => $membership->id,
            'report_key' => $definition->key(),
            'format' => (string) $request->string('format'),
            'label' => $definition->label(),
            'status' => 'pending',
        ]);

        GenerateReportExport::dispatch(
            (int) $export->id,
            $definition->key(),
            // Los mismos parámetros del reporte (agrupación, filtros), menos el formato. El motor los valida al correr.
            $request->except('format'),
            (int) $membership->tenant_id,
            (int) $membership->id,
            (int) $this->context->get()->requireActiveRole()->id,
        );

        return new JsonResponse(['data' => new ReportExportResource($export)], 202);
    }

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, ReportExport>>
     */
    public function index(): AnonymousResourceCollection
    {
        $exports = ReportExport::query()
            ->where('membership_id', $this->context->get()->requireMembership()->id)
            ->latest()
            ->limit(50)
            ->get();

        return ReportExportResource::collection($exports);
    }

    public function show(ReportExport $reportExport): JsonResponse
    {
        $this->assertOwner($reportExport);

        return new JsonResponse(['data' => new ReportExportResource($reportExport)]);
    }

    public function download(ReportExport $reportExport): StreamedResponse
    {
        $this->assertOwner($reportExport);

        if (! $reportExport->isReady() || $reportExport->file_path === null) {
            throw ReportException::notReady();
        }

        return Storage::disk('local')->download($reportExport->file_path, "{$reportExport->label}.{$reportExport->format}");
    }

    /**
     * Un export es de su AUTOR: nadie más lo ve ni lo descarga, ni siquiera dentro del mismo negocio. Un ajeno responde
     * 404 (el tenant scope ya lo acota; esto acota además por membresía).
     */
    private function assertOwner(ReportExport $export): void
    {
        if ((int) $export->membership_id !== (int) $this->context->get()->requireMembership()->id) {
            abort(404);
        }
    }
}

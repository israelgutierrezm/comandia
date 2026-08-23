<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Domain\Exceptions\ReportException;
use App\Modules\Reporting\Http\Requests\StoreScheduledReportRequest;
use App\Modules\Reporting\Http\Resources\ScheduledReportResource;
use App\Modules\Reporting\Infrastructure\Models\ScheduledReport;
use App\Modules\Reporting\Jobs\RunScheduledReport;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes programados (Tanda D3, D45).
 *
 * Programar exige el permiso fijo `reporting.schedules.manage` Y, además, el permiso del reporte concreto que se programa
 * —recibir por correo un reporte que no se puede ver sería una fuga—. Todo aquí está acotado al AUTOR (su membresía): un
 * programado es personal, como una vista guardada. El envío real corre en un job con el alcance del autor.
 */
final class ScheduledReportController
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly Authorize $authorize,
        private readonly ContextHolder $context,
    ) {}

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, ScheduledReport>>
     */
    public function index(): AnonymousResourceCollection
    {
        $schedules = ScheduledReport::query()
            ->where('membership_id', $this->membershipId())
            ->with('recipients')
            ->latest()
            ->get();

        return ScheduledReportResource::collection($schedules);
    }

    public function store(StoreScheduledReportRequest $request): JsonResponse
    {
        $definition = $this->registry->get((string) $request->string('report_key'))
            ?? throw ReportException::notFound((string) $request->string('report_key'));

        // Programar no otorga acceso: se exige el permiso del reporte, igual que el motor al correrlo.
        $this->authorize->authorize($definition->permission());

        $groupBy = $request->filled('group_by') ? (string) $request->string('group_by') : null;

        // La agrupación puede ser una o varias dimensiones (como en la pantalla de reportes); cada una tiene que estar en
        // la whitelist del reporte (ADR-006). El motor la vuelve a validar al correr.
        if ($groupBy !== null) {
            foreach (explode(',', $groupBy) as $dimension) {
                if (! in_array($dimension, $definition->groupings(), true)) {
                    throw ReportException::invalidGrouping($dimension);
                }
            }
        }

        $schedule = DB::transaction(function () use ($request, $definition, $groupBy): ScheduledReport {
            $schedule = ScheduledReport::create([
                'membership_id' => $this->membershipId(),
                'report_key' => $definition->key(),
                'format' => (string) $request->string('format'),
                'frequency' => (string) $request->string('frequency'),
                'group_by' => $groupBy,
            ]);

            /** @var list<string> $recipients */
            $recipients = $request->array('recipients');

            foreach ($recipients as $email) {
                $schedule->recipients()->create(['email' => $email]);
            }

            return $schedule;
        });

        return new JsonResponse(['data' => new ScheduledReportResource($schedule->load('recipients'))], 201);
    }

    /**
     * Corre el programado AHORA (útil para probar). Encola el mismo job del scheduler, con el contexto del autor.
     */
    public function runNow(ScheduledReport $scheduledReport): JsonResponse
    {
        $this->assertOwner($scheduledReport);

        RunScheduledReport::dispatch(
            (int) $scheduledReport->id,
            (int) $scheduledReport->tenant_id,
            (int) $scheduledReport->membership_id,
            (int) $this->context->get()->requireActiveRole()->id,
        );

        return new JsonResponse(status: 202);
    }

    public function destroy(ScheduledReport $scheduledReport): JsonResponse
    {
        $this->assertOwner($scheduledReport);

        $scheduledReport->delete();

        return new JsonResponse(status: 204);
    }

    /**
     * Un programado es de su AUTOR: nadie más lo ve ni lo corre, ni dentro del mismo negocio. Un ajeno responde 404 (el
     * tenant scope ya lo acota; esto acota además por membresía).
     */
    private function assertOwner(ScheduledReport $schedule): void
    {
        if ((int) $schedule->membership_id !== $this->membershipId()) {
            abort(404);
        }
    }

    private function membershipId(): int
    {
        return (int) $this->context->get()->requireMembership()->id;
    }
}

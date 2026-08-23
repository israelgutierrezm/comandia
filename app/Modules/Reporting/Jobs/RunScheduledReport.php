<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Configuration\Application\TenantMailer;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Notifications\Application\Notify;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Application\Export\ReportFileWriter;
use App\Modules\Reporting\Application\RunReport;
use App\Modules\Reporting\Infrastructure\Models\ReportExport;
use App\Modules\Reporting\Infrastructure\Models\ScheduledReport;
use App\Modules\Reporting\Mail\ScheduledReportMail;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Support\Queue;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Corre un reporte programado (Tanda D3): genera el export del periodo cerrado, lo envía por el correo del negocio (D1) y
 * avisa al autor (D2). En la cola `exports`, con el contexto del autor reconstruido (mismo permiso y alcance).
 */
final class RunScheduledReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $scheduledReportId,
        private readonly int $tenantId,
        private readonly int $membershipId,
        // El rol activo con el que corre el reporte. Puede venir null desde el scheduler (que no tiene contexto de usuario);
        // en ese caso el job usa el rol por omisión de la membresía, ya DENTRO del contexto del tenant.
        private readonly ?int $activeRoleId = null,
    ) {
        $this->onQueue(Queue::Exports->value);
    }

    public function handle(
        RunReport $runner,
        ReportFileWriter $writer,
        ReportRegistry $registry,
        TenantMailer $mailer,
        Notify $notify,
        ContextHolder $holder,
        TenantContext $tenant,
    ): void {
        $tenant->runFor($this->tenantId, function () use ($runner, $writer, $registry, $mailer, $notify, $holder): void {
            $schedule = ScheduledReport::query()->with('recipients')->find($this->scheduledReportId);
            $definition = $registry->get((string) $schedule?->report_key);

            if ($schedule === null || $definition === null) {
                return;
            }

            $membership = TenantMembership::query()->with('user')->findOrFail($this->membershipId);
            $branch = $membership->last_active_branch_id === null
                ? null
                : Branch::query()->find($membership->last_active_branch_id);

            // Sin rol explícito (viene del scheduler), se usa el rol por omisión del autor. La resolución ocurre aquí,
            // dentro del contexto del tenant, así que no hay lectura cross-tenant.
            $roleId = $this->activeRoleId ?? $membership->default_role_id;

            $context = RequestContext::forMember(
                Tenant::query()->findOrFail($this->tenantId),
                $membership->user,
                $membership,
                $roleId === null ? null : Role::query()->find($roleId),
                $branch,
            );

            $holder->runWith($context, function () use ($schedule, $definition, $runner, $writer, $mailer, $notify, $branch): void {
                $result = $runner->run($schedule->report_key, $this->params($schedule, $definition, $branch));

                // Se guarda como export descargable, además de enviarlo.
                $export = ReportExport::create([
                    'membership_id' => $this->membershipId,
                    'report_key' => $schedule->report_key,
                    'format' => $schedule->format,
                    'label' => $definition->label(),
                    'status' => 'pending',
                ]);

                $relative = 'exports/'.$export->ulid.'.'.$schedule->format;
                Storage::disk('local')->makeDirectory('exports');
                $absolute = Storage::disk('local')->path($relative);
                $rows = $writer->write($result, $schedule->format, $definition->label(), $absolute);

                $export->update(['status' => 'ready', 'file_path' => $relative, 'row_count' => $rows, 'completed_at' => now()]);

                // Enviar el archivo a cada destinatario, con el remitente del negocio.
                $from = $mailer->settings();
                $fileName = $definition->label().'.'.$schedule->format;

                foreach ($schedule->recipients as $recipient) {
                    $mailer->send($recipient->email, new ScheduledReportMail(
                        $definition->label(),
                        $from?->from_address ?? 'no-reply@comandia.test',
                        $from?->from_name ?? 'Comandia',
                        $absolute,
                        $fileName,
                    ));
                }

                $notify->toMembership(
                    $this->membershipId,
                    'scheduled_report',
                    'Reporte programado enviado',
                    "«{$definition->label()}» se envió a {$schedule->recipients->count()} destinatario(s).",
                    '/admin/reportes',
                );

                $schedule->forceFill(['last_run_on' => now()->toDateString()])->save();
            });
        });
    }

    /**
     * Los parámetros del reporte: la agrupación guardada + el rango del periodo CERRADO según la frecuencia, en la zona de
     * la sucursal.
     *
     * @return array<string, mixed>
     */
    private function params(ScheduledReport $schedule, \App\Modules\Shared\Domain\Reporting\ReportDefinition $definition, ?Branch $branch): array
    {
        $params = [];

        if ($schedule->group_by !== null && $schedule->group_by !== '') {
            $params['group_by'] = $schedule->group_by;
        }

        $timezone = (string) ($branch?->timezone ?? 'UTC');
        $now = CarbonImmutable::now($timezone);

        [$from, $to] = match ($schedule->frequency) {
            'weekly' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'monthly' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            default => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()], // daily → ayer
        };

        foreach ($definition->filters() as $filter) {
            if ($filter->operator === 'date_range') {
                $params[$filter->key.'_from'] = $from->toDateString();
                $params[$filter->key.'_to'] = $to->toDateString();
                break;
            }
        }

        return $params;
    }
}

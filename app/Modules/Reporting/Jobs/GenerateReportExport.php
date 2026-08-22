<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Application\Export\ReportFileWriter;
use App\Modules\Reporting\Application\RunReport;
use App\Modules\Reporting\Infrastructure\Models\ReportExport;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Genera un export de reporte, en la cola `exports` (Tanda B, ADR-006 regla 5).
 *
 * ## Corre sin request: reconstruye el contexto
 *
 * Un job no tiene middleware ni sesión, así que aquí se **reconstruye** el contexto que el motor necesita para aplicar el
 * mismo permiso y alcance que tenía quien pidió el export: el tenant (para el global scope), y la membresía con su rol
 * activo (capturado al encolar) y su sucursal. Sin esto, el reporte correría sin scope —el peor error posible—.
 *
 * ## Idempotente
 *
 * Opera sobre una fila `report_exports` concreta; si ya no está `pending` (otro intento la completó, o se borró), no hace
 * nada. Re-encolarlo no duplica el archivo ni el trabajo.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        private readonly int $exportId,
        private readonly string $reportKey,
        private readonly array $params,
        private readonly int $tenantId,
        private readonly int $membershipId,
        private readonly int $activeRoleId,
    ) {
        $this->onQueue(Queue::Exports->value);
    }

    public function handle(RunReport $runner, ReportFileWriter $writer, ContextHolder $holder, TenantContext $tenant): void
    {
        $tenant->runFor($this->tenantId, function () use ($runner, $writer, $holder): void {
            $export = ReportExport::query()->find($this->exportId);

            // Idempotencia: sólo se procesa una vez. Un re-despacho encuentra la fila ya `ready`/`failed` y se retira.
            if ($export === null || $export->status !== 'pending') {
                return;
            }

            try {
                $membership = TenantMembership::query()->with('user')->findOrFail($this->membershipId);
                $tenantModel = Tenant::query()->findOrFail($this->tenantId);
                $role = Role::query()->find($this->activeRoleId);
                $branch = $membership->last_active_branch_id === null
                    ? null
                    : Branch::query()->find($membership->last_active_branch_id);

                $context = RequestContext::forMember($tenantModel, $membership->user, $membership, $role, $branch);

                // El motor corre con el contexto restablecido: mismo permiso, mismo alcance de sucursal que en la petición.
                $result = $holder->runWith($context, fn (): array => $runner->run($this->reportKey, $this->params));

                $relative = 'exports/'.$export->ulid.'.'.$export->format;
                Storage::disk('local')->makeDirectory('exports');
                $rows = $writer->write($result, $export->format, $export->label, Storage::disk('local')->path($relative));

                $export->update([
                    'status' => 'ready',
                    'file_path' => $relative,
                    'row_count' => $rows,
                    'completed_at' => now(),
                ]);
            } catch (Throwable $e) {
                // El fallo se registra en la fila —la pantalla lo muestra— y no se relanza: un retry sólo re-correría lo
                // mismo. El usuario reintenta pidiendo otro export.
                $export->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        });
    }
}

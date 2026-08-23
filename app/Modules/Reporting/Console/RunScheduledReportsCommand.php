<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Infrastructure\Models\ScheduledReport;
use App\Modules\Reporting\Jobs\RunScheduledReport;
use Illuminate\Console\Command;

/**
 * Despacha los reportes programados que toca correr hoy (Tanda D3).
 *
 * Es un comando de SISTEMA: recorre los programados de TODOS los negocios (fuera del scope de tenant, como el scheduler
 * mismo), decide cuáles tocan por su frecuencia y que no se hayan corrido hoy, y encola un job por cada uno. El job corre
 * con el contexto del autor. Se agenda con el scheduler de Laravel (schedule:run diario).
 */
final class RunScheduledReportsCommand extends Command
{
    protected $signature = 'reports:run-scheduled';

    protected $description = 'Despacha los reportes programados que toca correr hoy';

    public function handle(): int
    {
        $now = now();
        $today = $now->toDateString();

        // Qué frecuencias tocan hoy: diaria siempre; semanal el lunes; mensual el día 1.
        $frequencies = ['daily'];

        if ($now->dayOfWeekIso === 1) {
            $frequencies[] = 'weekly';
        }

        if ($now->day === 1) {
            $frequencies[] = 'monthly';
        }

        // Cross-tenant a propósito: es infraestructura, no dominio (como el super admin). El job reestablece el tenant.
        $due = ScheduledReport::query()
            ->withoutGlobalScopes()
            ->whereIn('frequency', $frequencies)
            ->where(fn ($q) => $q->whereNull('last_run_on')->orWhere('last_run_on', '<', $today))
            ->get();

        foreach ($due as $schedule) {
            // Sin rol explícito: el job usa el rol por omisión del autor, ya dentro del contexto del tenant. Así el
            // comando no lee ningún modelo de dominio entre negocios; sólo enumera los programados para despacharlos.
            RunScheduledReport::dispatch(
                (int) $schedule->id,
                (int) $schedule->tenant_id,
                (int) $schedule->membership_id,
            );
        }

        $this->info("Programados despachados: {$due->count()}");

        return self::SUCCESS;
    }
}

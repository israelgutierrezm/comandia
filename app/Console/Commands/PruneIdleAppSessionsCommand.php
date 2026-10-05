<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\AppSessions;
use Illuminate\Console\Command;

/**
 * Cierra las sesiones de la app que llevan 60 días sin usarse (diseño de acceso, fase 1, decisión 5).
 *
 * Los tokens de Sanctum no caducan solos, y el de un teléfono perdido hace un año seguía entrando mientras nadie
 * suspendiera a su dueño. Esto corre a diario desde el programador; cada cierre queda en la bitácora del negocio del
 * token, a nombre de «Sistema», con `how = idle`. Para quien lo usa a diario no cambia nada: el último uso se renueva
 * con cada petición.
 */
final class PruneIdleAppSessionsCommand extends Command
{
    protected $signature = 'comandia:app-sessions:prune';

    protected $description = 'Cierra las sesiones de la app que llevan 60 días sin usarse';

    public function handle(AppSessions $sessions): int
    {
        $cerradas = $sessions->pruneIdle();

        $this->info(sprintf('Sesiones de la app cerradas por falta de uso: %d.', $cerradas));

        return self::SUCCESS;
    }
}

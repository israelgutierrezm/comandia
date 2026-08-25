<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Console\CreatePlatformAdminCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo de super administración del SaaS. Las rutas y migraciones las engancha `ModuleServiceProvider`; aquí sólo se
 * registra el comando de alta del primer super admin (que no puede crearse desde una UI que ya exige serlo).
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreatePlatformAdminCommand::class,
            ]);
        }
    }
}

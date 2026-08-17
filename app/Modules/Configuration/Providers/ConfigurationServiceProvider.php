<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Providers;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del módulo Configuration.
 */
final class ConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton con alcance de request: dentro de una petición la configuración no
        // cambia, y resolver instancias nuevas repetiría la lectura de cache.
        $this->app->singleton(
            Settings::class,
            fn ($app): Settings => new Settings(
                $app->make(TenantContext::class),
                $app->make(Cache::class),
            ),
        );
    }
}

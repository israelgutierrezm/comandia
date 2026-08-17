<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Domain\Tenancy\TenantScope;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del shared kernel.
 *
 * `TenantContext` es singleton: un request, un tenant
 * (ARQUITECTURA_MAESTRA §3). Si se resolviera una instancia nueva por inyección,
 * el global scope leería un contexto vacío y el aislamiento dependería del azar
 * de quién resolvió primero.
 *
 * `TenantScope` también es singleton, y no por rendimiento: así todos los modelos
 * comparten exactamente la misma instancia del scope, y el test estructural puede
 * compararla por identidad además de por clase.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        $this->app->singleton(
            TenantScope::class,
            fn ($app) => new TenantScope($app->make(TenantContext::class)),
        );
    }
}

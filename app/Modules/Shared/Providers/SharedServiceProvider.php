<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Domain\Tenancy\TenantScope;
use App\Modules\Shared\Http\Middleware\EnsureModuleActive;
use App\Modules\Shared\Http\Middleware\EnsurePermission;
use App\Modules\Shared\Http\Middleware\EnsureWritePermission;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del shared kernel.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons de contexto: un request, un tenant, un contexto
        // (ARQUITECTURA_MAESTRA §3). Si se resolvieran instancias nuevas por inyección,
        // el global scope leería un contexto vacío y el aislamiento dependería del azar
        // de quién resolvió primero.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(ContextHolder::class);

        // También singleton, y no por rendimiento: así todos los modelos comparten
        // exactamente la misma instancia del scope y el test estructural puede
        // compararla por identidad además de por clase.
        $this->app->singleton(
            TenantScope::class,
            fn ($app): TenantScope => new TenantScope($app->make(TenantContext::class)),
        );

        $this->app->singleton(
            ModuleGate::class,
            fn ($app): ModuleGate => new ModuleGate(
                $app->make(TenantContext::class),
                $app->make(Cache::class),
            ),
        );

        $this->app->singleton(
            Authorize::class,
            fn ($app): Authorize => new Authorize(
                $app->make(ContextHolder::class),
                $app->make(Cache::class),
                $app->make(ModuleGate::class),
            ),
        );

        $this->app->bind(
            DocumentNumberAllocator::class,
            fn ($app): DocumentNumberAllocator => new DocumentNumberAllocator(
                DB::connection(),
                $app->make(TenantContext::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->registerMiddlewareAliases();
    }

    /**
     * Alias de middleware del kernel.
     *
     * `can` se REEMPLAZA a propósito: el `can` de Laravel evalúa la suma de roles del
     * usuario y aquí opera el rol activo (D9). Registrarlo con el nombre que un
     * desarrollador de Laravel va a escribir por instinto es deliberado —conviene que
     * ese instinto lleve al camino correcto en lugar de al que concede permisos de más—.
     */
    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('can', EnsurePermission::class);
        $router->aliasMiddleware('can.write', EnsureWritePermission::class);
        $router->aliasMiddleware('module', EnsureModuleActive::class);
    }
}

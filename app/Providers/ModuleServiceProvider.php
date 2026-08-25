<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Cargador del monolito modular (ARQUITECTURA_MAESTRA §2).
 *
 * Recorre el registro declarativo de config/comandia.php —nunca el sistema de
 * archivos— y engancha, si existen, los artefactos de cada módulo:
 *
 *   app/Modules/{Modulo}/database/migrations   → migraciones del módulo
 *   app/Modules/{Modulo}/Providers/{Modulo}ServiceProvider.php
 *   app/Modules/{Modulo}/Http/Routes/api.php     → prefijo api/v1, middleware api
 *   app/Modules/{Modulo}/Http/Routes/web.php     → middleware web
 *   app/Modules/{Modulo}/Http/Routes/public.php  → superficies públicas sin auth
 *
 * Por qué el registro y no un glob del disco: una carpeta creada por error o a
 * medio renombrar no debe convertirse en un módulo cargado en silencio. Si un
 * módulo existe, está declarado.
 *
 * Este proveedor NO decide dependencias entre módulos ni activación por tenant:
 * la activación por tenant es middleware (Iteración 1) y las dependencias las
 * vigila un test estructural.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->moduleNames() as $module) {
            $provider = "App\\Modules\\{$module}\\Providers\\{$module}ServiceProvider";

            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->moduleNames() as $module) {
            $path = $this->modulePath($module);

            $migrations = "{$path}/database/migrations";

            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }

            $this->registerRoutes($module, $path);
        }
    }

    /**
     * @return list<string>
     */
    private function moduleNames(): array
    {
        /** @var array<string, array<string, mixed>> $modules */
        $modules = (array) config('comandia.modules', []);

        return array_keys($modules);
    }

    private function modulePath(string $module): string
    {
        return $this->app->path("Modules/{$module}");
    }

    private function registerRoutes(string $module, string $path): void
    {
        $api = "{$path}/Http/Routes/api.php";

        if (is_file($api)) {
            Route::middleware('api')
                ->prefix((string) config('comandia.api.prefix'))
                ->name((string) config('comandia.api.name_prefix'))
                ->group($api);
        }

        $web = "{$path}/Http/Routes/web.php";

        if (is_file($web)) {
            Route::middleware('web')->group($web);
        }

        // Superficies públicas sin autenticación (menú QR, tienda en línea).
        // Namespace de rutas propio y cache agresivo: ARQUITECTURA_MAESTRA §8.
        $public = "{$path}/Http/Routes/public.php";

        if (is_file($public)) {
            Route::middleware('public')->group($public);
        }

        // Super administración del SaaS: grupo de middleware propio, aislado del `web` de los negocios (sin contexto de
        // tenant, con cookie de sesión distinta). Sólo el módulo Platform trae este archivo.
        $platform = "{$path}/Http/Routes/platform.php";

        if (is_file($platform)) {
            Route::middleware('platform')->group($platform);
        }
    }
}

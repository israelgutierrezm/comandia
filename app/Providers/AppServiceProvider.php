<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->enforceModelRigor();
        $this->resolveModuleFactories();
        $this->registerRateLimiters();
        $this->registerFixtureMigrations();
    }

    /**
     * Factories por módulo (ARQUITECTURA_MAESTRA §11).
     *
     * La convención de Laravel resuelve `App\Models\Foo` → `Database\Factories\FooFactory`
     * y no sabe nada de `app/Modules`. Sin este resolutor buscaría
     * `Database\Factories\Modules\Tenancy\Infrastructure\Models\TenantFactory`.
     *
     * Las factories viven en `database/factories/{Modulo}/` y no dentro del módulo por
     * una razón práctica de Windows: un módulo con `database/` (migraciones, en
     * minúscula por convención de §2) y `Database/` (namespace PSR-4, en mayúscula)
     * serían la MISMA carpeta en un sistema de archivos insensible a mayúsculas y dos
     * distintas en Linux. Ese tipo de diferencia rompe el despliegue y no la ve nadie
     * hasta que ya está en el servidor.
     */
    private function resolveModuleFactories(): void
    {
        Factory::guessFactoryNamesUsing(function (string $model): string {
            if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\/', $model, $matches) === 1) {
                return 'Database\\Factories\\'.$matches[1].'\\'.class_basename($model).'Factory';
            }

            return 'Database\\Factories\\'.class_basename($model).'Factory';
        });
    }

    /**
     * Tablas de apoyo de las pruebas del shared kernel.
     *
     * Se registran sólo bajo `runningUnitTests()`. Viven fuera de las migraciones
     * de la aplicación porque el mecanismo de aislamiento debe poder probarse sin
     * atar las pruebas del kernel a la forma de las tablas de negocio.
     */
    private function registerFixtureMigrations(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        $this->loadMigrationsFrom(base_path('tests/Fixtures/database/migrations'));
    }

    /**
     * Endurecimiento global de Eloquent.
     *
     * Las tres reglas atacan errores que en este dominio son caros:
     *
     * - preventLazyLoading: una vista de piso o un listado de órdenes con N+1
     *   silencioso degrada el POS en la hora pico. Falla en desarrollo y
     *   pruebas; en producción no se rompe la operación por esto.
     * - preventSilentlyDiscardingAttributes: un ``fill()`` con una columna mal
     *   escrita hoy se ignora en silencio y el dato de negocio nunca se guarda.
     * - preventAccessingMissingAttributes: leer un atributo no cargado devuelve
     *   null y un total de cuenta calculado sobre null es un cobro incorrecto.
     */
    private function enforceModelRigor(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Umbral de alerta por request lento en base de datos. El POS opera con
        // presupuesto de latencia estrecho; una consulta de 5s ya es un incidente.
        DB::whenQueryingForLongerThan(
            5_000,
            fn () => logger()->warning('Consulta lenta detectada (>5s).'),
        );
    }

    /**
     * Limitadores de tasa (ARQUITECTURA_MAESTRA §8, D55).
     *
     * Los límites de login y PIN se afinan en la Iteración 1, cuando exista la
     * membresía con bloqueo por intentos. Aquí quedan los dos que ya tienen
     * superficie: la API autenticada y las superficies públicas.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Superficies públicas (menú QR, tienda): sin usuario, límite por IP y
        // más holgado porque una mesa entera escanea el mismo QR desde la misma
        // red y no debe recibir 429.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // Autorización por PIN (ADR-008, límite 5). Estrecho a propósito: un PIN de cuatro
        // dígitos es un espacio de 10,000 combinaciones, y sin límite de intentos el
        // bloqueo por membresía sólo detiene el ataque dirigido a una persona, no el
        // barrido sobre muchas.
        //
        // La llave combina terminal e IP: en una sucursal todas las terminales comparten
        // salida a internet, así que limitar sólo por IP castigaría a una sucursal entera
        // por el error de una caja.
        RateLimiter::for('pin', fn (Request $request) => Limit::perMinute(10)
            ->by($request->header('X-Terminal', '').'|'.$request->ip()));
    }
}

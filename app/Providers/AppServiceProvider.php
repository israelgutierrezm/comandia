<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
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
        $this->registerRateLimiters();
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
    }
}

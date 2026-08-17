<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Providers;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\Exceptions\InvalidSettingValueException;
use App\Modules\Configuration\Domain\Exceptions\SettingScopeViolationException;
use App\Modules\Configuration\Domain\Exceptions\UnknownSettingKeyException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Registro del módulo Configuration.
 */
final class ConfigurationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce las excepciones de dominio del módulo a respuestas HTTP.
     *
     * Se registra AQUÍ y no en el manejador global de `Shared` por la regla de dependencias de
     * ARQUITECTURA_MAESTRA §2: el kernel compartido no debe conocer las excepciones de cada
     * módulo. Cada módulo declara cómo se ven las suyas desde fuera.
     *
     * Sin esto, escribir una llave en un nivel que no admite override devolvía **500**: un error
     * del cliente presentado como fallo del servidor. El síntoma es engañoso —parece un bug del
     * sistema cuando es una petición incorrecta— y además ensucia el monitoreo de errores con
     * ruido que nadie puede accionar.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // El nivel de override no está permitido para esa llave: petición inválida.
        $handler->renderable(function (SettingScopeViolationException $e, Request $request): ?JsonResponse {
            return $this->problem($request, 'unprocessable', $e->getMessage(), 422);
        });

        // El valor no corresponde al tipo o al conjunto declarado.
        $handler->renderable(function (InvalidSettingValueException $e, Request $request): ?JsonResponse {
            return $this->problem($request, 'unprocessable', $e->getMessage(), 422);
        });

        // La llave no existe: el recurso no existe, no es un dato inválido.
        $handler->renderable(function (UnknownSettingKeyException $e, Request $request): ?JsonResponse {
            return $this->problem($request, 'not_found', $e->getMessage(), 404);
        });
    }

    private function problem(Request $request, string $type, string $title, int $status): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        // Mismo formato que el resto de la API (ARQUITECTURA_MAESTRA §8).
        return new JsonResponse([
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ], $status);
    }

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

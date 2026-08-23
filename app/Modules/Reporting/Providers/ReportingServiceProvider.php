<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Console\RunScheduledReportsCommand;
use App\Modules\Reporting\Domain\Exceptions\ReportException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Reporting` (capa analytics).
 *
 * `Reporting` es el MOTOR: no declara `depends_on` de dominio y no conoce ningún módulo. Lee las definiciones que cada
 * dueño registra en el `ReportRegistry` del kernel (ADR-009). Aquí sólo se traducen sus excepciones a HTTP.
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();

        if ($this->app->runningInConsole()) {
            $this->commands([RunScheduledReportsCommand::class]);
        }
    }

    /**
     * Un reporte inexistente responde 404; un parámetro fuera de la whitelist, 422 (ADR-006: «lo que no está declarado no
     * se puede pedir»). El formato es el de siempre (§8): `type` estable, texto en español.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (ReportException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => match ($e->status) {
                    404 => 'not_found',
                    409 => 'conflict',
                    default => 'validation_error',
                },
                'title' => $e->getMessage(),
                'status' => $e->status,
            ], $e->status);
        });
    }
}

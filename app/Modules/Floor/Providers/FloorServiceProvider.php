<?php

declare(strict_types=1);

namespace App\Modules\Floor\Providers;

use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Floor`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`, nunca por descubrimiento
 * de disco (D64).
 */
final class FloorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce los invariantes del salón a 422 con el motivo en español.
     *
     * Se reconoce la excepción por su TIPO y no por su origen en el trace: los invariantes de modelo se lanzan desde un
     * closure del despachador de eventos de Eloquent, así que el primer marco del trace es el despachador. Es la lección
     * que costó un 500 en el módulo `Finance` de esta misma iteración.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (TableInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['table' => [$e->getMessage()]],
            ], 422);
        });
    }
}

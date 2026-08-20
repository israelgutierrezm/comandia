<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Customers`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`, nunca por descubrimiento
 * de disco (D64).
 */
final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Los invariantes del crédito responden 409.
     *
     * «El crédito está suspendido», «no se abona más de lo que se debe», «no hay caja abierta»: en todos, los datos que
     * llegaron son correctos y lo que no encaja es el estado. Un 422 mandaría a quien cobra a revisar campos que están
     * bien.
     *
     * El único caso que sí es 422 —el signo equivocado de un movimiento— no llega nunca desde HTTP: lo lanza la puerta
     * de escritura contra código que la llamó mal, y ahí un 409 tampoco engaña a nadie.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (CreditInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'conflict',
                'title' => $e->getMessage(),
                'status' => 409,
            ], 409);
        });
    }
}

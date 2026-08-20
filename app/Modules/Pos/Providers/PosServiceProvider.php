<?php

declare(strict_types=1);

namespace App\Modules\Pos\Providers;

use App\Modules\Pos\Domain\Exceptions\CashSessionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Pos`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`, nunca por descubrimiento
 * de disco (D64).
 */
final class PosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Los invariantes de caja responden 409 y no 422.
     *
     * «Esta caja ya tiene un turno abierto» o «este turno ya está cerrado» no son errores de captura: los datos que
     * llegaron son correctos y no hay ningún campo que corregir. Lo que no encaja es el ESTADO del negocio, y para eso
     * está el 409 — el mismo criterio con el que D170 eligió 409 para la autorización pendiente y con el que el paso 3
     * lo eligió para el último método de pago activo.
     *
     * Un 422 mandaría al cajero a revisar el formulario, que es el sitio equivocado: lo que tiene que hacer es cerrar el
     * otro turno.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (CashSessionException $e, Request $request): ?JsonResponse {
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

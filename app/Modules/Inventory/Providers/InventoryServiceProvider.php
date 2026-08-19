<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Domain\Exceptions\RequiresAuthorizationException;
use App\Modules\Inventory\Domain\Exceptions\StockCountInvariantException;
use App\Modules\Inventory\Domain\Exceptions\StockMovementInvariantException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Inventory`.
 *
 * Declara depender de `Catalog` en `config/comandia.php` y el candado de fronteras lo impone: lee artículos y
 * les cuenta existencias, y **nunca les escribe**. El artículo no sabe cuánto hay de él — eso vive aquí.
 *
 * `StockMovementRecorded` se emite desde el primer día y todavía no tiene suscriptores: los previstos —agotar
 * lotes, mínimos, tiempo real— llegan en pasos posteriores. Emitirlo después obligaría a revisar cada llamador
 * para no dejar movimientos silenciosos.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce las excepciones de dominio del módulo a respuestas HTTP.
     *
     * Se registra aquí y no en el manejador global por la regla de dependencias de §2: el kernel no conoce las
     * excepciones de cada módulo. Mismo patrón que `Catalog` y `Costing`.
     *
     * Sin esto, registrar una merma con dirección de entrada devolvería **500**, y ese caso importa: quien lo
     * intenta suele ser una integración o un job, y el mensaje del dominio dice exactamente qué contradicción
     * hay. Un 500 la esconde.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (StockMovementInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // 422 y no 409: lo que llegó en el cuerpo es corregible —la cantidad, la dirección, el lote—, así
            // que el error pertenece a la entrada y no a un conflicto de estado.
            return new JsonResponse([
                'type' => 'validation_error',
                'title' => 'Los datos enviados no son válidos.',
                'status' => 422,
                'errors' => ['quantity' => [$e->getMessage()]],
            ], 422);
        });

        // Los invariantes del CONTEO también son 422, y por la misma razón, aunque el error no esté en un campo:
        // «este conteo ya está cerrado» o «ya hay uno abierto en este almacén» son situaciones que quien pidió la
        // operación puede resolver, y el mensaje dice cómo.
        $handler->renderable(function (StockCountInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['stock_count' => [$e->getMessage()]],
            ], 422);
        });

        // Falta de autorización por monto responde **409 y no 422**, y la diferencia importa para el cliente: no
        // hay nada en el cuerpo que corregir —los datos son correctos y la operación es legítima— lo que falta es
        // que otra persona autorice. Un 422 mandaría al usuario a revisar los campos, que es el sitio equivocado.
        //
        // El código `authorization_required` es lo que la UI usa para abrir el diálogo de PIN en lugar de pintar
        // un error: es la misma operación esperando una firma, no una operación fallida.
        //
        // Un solo `renderable` para la clase BASE, y no uno por operación: el contrato HTTP tiene que ser uno solo.
        // Con un mapeo por excepción, el cliente tendría que reconocer una respuesta distinta por operación y a la
        // tercera se le olvidaría a alguien — el permiso viaja en la respuesta justamente para que no le haga falta.
        $handler->renderable(function (RequiresAuthorizationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'authorization_required',
                'title' => $e->getMessage(),
                'status' => 409,
                'required_permission' => $e->requiredPermission(),
            ], 409);
        });
    }
}

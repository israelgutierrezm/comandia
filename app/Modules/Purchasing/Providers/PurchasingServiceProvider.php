<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Providers;

use App\Modules\Purchasing\Domain\Exceptions\PurchaseReceiptInvariantException;
use App\Modules\Purchasing\Domain\Exceptions\SupplierPriceInvariantException;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Listeners\RecordSupplierPriceFromReceipt;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Purchasing`.
 *
 * Declara depender de `Catalog` en `config/comandia.php` y el candado de fronteras lo impone: lee artículos y sus
 * presentaciones de compra para normalizar precios, y **nunca les escribe**. El artículo no sabe a cuánto se lo venden
 * — eso vive aquí.
 *
 * La conexión con `Inventory` y `Costing` llega en el paso 9, y será **por eventos**: confirmar una recepción emitirá un
 * evento del que `Inventory` registrará los movimientos y `Costing` capturará el costo con `origin = purchase` — el
 * valor del enum que existe desde la Iteración 2 esperando este momento. Este módulo no escribirá en ninguno de los
 * dos directamente.
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();

        // La observación de precio la deja este módulo, porque la tabla es suya. Es un oyente y no una llamada dentro
        // del servicio de confirmación por una razón modesta: que los tres efectos de confirmar se lean en el mismo
        // sitio —la lista de oyentes del evento— en lugar de dos ahí y uno escondido dentro de una transacción.
        Event::listen(PurchaseReceiptConfirmed::class, RecordSupplierPriceFromReceipt::class);
    }

    /**
     * Traduce las excepciones de dominio del módulo a respuestas HTTP.
     *
     * Se registra aquí y no en el manejador global por la regla de dependencias de §2: el kernel no conoce las
     * excepciones de cada módulo. Mismo patrón que `Catalog`, `Costing` e `Inventory`.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // Los invariantes de la RECEPCIÓN: proveedor de baja, sin renglones, ya confirmada, reversa de una reversa.
        // Todos 422 y corregibles por quien pidió la operación — el mensaje dice cómo.
        $handler->renderable(function (PurchaseReceiptInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['receipt' => [$e->getMessage()]],
            ], 422);
        });

        // 422 y no 409: capturar un precio a un proveedor dado de baja, o un cero, son cosas que quien lo pidió puede
        // corregir — reactivando al proveedor o escribiendo el precio correcto. El mensaje dice cuál de las dos.
        $handler->renderable(function (SupplierPriceInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['price' => [$e->getMessage()]],
            ], 422);
        });
    }
}

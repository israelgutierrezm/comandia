<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Domain\Exceptions\ProductionInvariantException;
use App\Modules\Inventory\Domain\Exceptions\StockCountInvariantException;
use App\Modules\Inventory\Domain\Exceptions\StockMovementInvariantException;
use App\Modules\Inventory\Domain\Exceptions\TransferInvariantException;
use App\Modules\Inventory\Listeners\DeductSaleFromInventory;
use App\Modules\Inventory\Listeners\RegisterStockFromPurchaseReceipt;
use App\Modules\Inventory\Reporting\WasteReport;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // El efecto cruzado de una recepción confirmada, por evento y nunca por llamada directa (ADR-001, §3.2).
        // `Purchasing` emite el hecho; este módulo, que es dueño del kardex, decide qué escribir.
        Event::listen(PurchaseReceiptConfirmed::class, RegisterStockFromPurchaseReceipt::class);

        // Lo que se vendió se descuenta del inventario, EN COLA: es el único camino asíncrono de la iteración (§6.2).
        // El oyente sólo encola; el trabajo lo hace el job, porque un platillo con receta de tres niveles puede tocar
        // veinte artículos y eso no puede correr dentro del cobro.
        Event::listen(PosAccountPaid::class, DeductSaleFromInventory::class);

        // El reporte de mermas lo registra su dueño en el motor (ADR-007): la merma es un movimiento del kardex, que es de
        // `Inventory`; el motor no lo toca.
        $this->app->make(ReportRegistry::class)->register(new WasteReport());
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

        // Los invariantes de la TRANSFERENCIA: transiciones no permitidas, pasos apagados, cancelar algo que ya
        // salió, enviar más de lo pedido. Todos son 422 y no 409 porque todos son corregibles por quien pidió la
        // operación —cambiando la cantidad, activando el paso, o recibiendo en lugar de cancelar— y el mensaje dice
        // cuál es el camino.
        $handler->renderable(function (TransferInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['transfer' => [$e->getMessage()]],
            ], 422);
        });

        // Los invariantes de la PRODUCCIÓN: artículo no producible, sin receta activa, receta con un componente
        // que no se inventaría, orden ya completada. Todos 422 y corregibles por quien pidió la operación — y el
        // mensaje dice dónde: en el catálogo, en la receta, o haciendo otra orden.
        $handler->renderable(function (ProductionInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['production' => [$e->getMessage()]],
            ], 422);
        });

        // El 409 `authorization_required` lo traduce el KERNEL desde el paso 6 de la Iteración 4.
        //
        // Estaba aquí, y con el POS habría hecho falta duplicarlo o que `Pos` importara la excepción base de este
        // módulo. Lo primero da dos contratos que se desvían; lo segundo mete una flecha de dependencia entre dos
        // módulos que no tienen nada que ver. Ver `SharedServiceProvider::mapAuthorizationRequiredToHttp()`.
    }
}

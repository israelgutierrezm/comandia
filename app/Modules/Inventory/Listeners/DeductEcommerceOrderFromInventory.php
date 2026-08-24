<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Jobs\DeductSoldItems;
use App\Modules\Shared\Domain\Events\EcommerceOrderPaid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descuenta del inventario los insumos de un pedido de e-commerce pagado (Iteración 8, Tanda C).
 *
 * El equivalente e-commerce de {@see DeductSaleFromInventory}: reusa el MISMO job `DeductSoldItems`, que resuelve recetas
 * y escribe el kardex en cola. El pedido no tiene áreas de preparación (eso es la bandeja de aceptación, Tanda D), así que
 * los items descuentan del almacén de la sucursal. `Inventory` no conoce a `Ecommerce`: reacciona al evento del kernel.
 */
final class DeductEcommerceOrderFromInventory
{
    public function handle(EcommerceOrderPaid $event): void
    {
        if ($event->items === []) {
            return;
        }

        // Se traduce a la forma que espera el job del POS; `item_ulid` es sólo para el log, y sin área se usa el almacén
        // de la sucursal.
        $items = [];

        foreach (array_values($event->items) as $i => $line) {
            $items[] = [
                'item_ulid' => $event->orderUlid.'-'.$i,
                'article_id' => (int) $line['article_id'],
                'quantity' => (string) $line['quantity'],
                'preparation_area_id' => null,
                'is_courtesy' => false,
            ];
        }

        try {
            DeductSoldItems::dispatch($event->tenantId, $event->branchId, $event->orderUlid, $items);
        } catch (Throwable $e) {
            Log::error('No se pudo encolar el descuento de inventario de un pedido de e-commerce.', [
                'tenant_id' => $event->tenantId,
                'order' => $event->orderUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

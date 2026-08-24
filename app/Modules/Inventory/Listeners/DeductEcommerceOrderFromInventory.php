<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Jobs\DeductSoldItems;
use App\Modules\Shared\Domain\Events\EcommerceOrderAccepted;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descuenta del inventario los insumos de un pedido de e-commerce **aceptado** (Iteración 8, Tanda D).
 *
 * El equivalente e-commerce de {@see DeductSaleFromInventory}: reusa el MISMO job `DeductSoldItems`, que resuelve recetas
 * y escribe el kardex en cola. Descuenta al ACEPTAR, no al pagar (Tanda D): un pedido rechazado nunca movió stock. Cada
 * línea ya trae su **área de preparación** congelada (resuelta al hacer el pedido vía `AreaRouter`), así que se descuenta
 * del almacén de esa área —como el POS— y, sin área, del almacén de la sucursal. `Inventory` no conoce a `Ecommerce`:
 * reacciona al evento del kernel.
 */
final class DeductEcommerceOrderFromInventory
{
    public function handle(EcommerceOrderAccepted $event): void
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
                'preparation_area_id' => $line['preparation_area_id'], // el área congelada; sin ella, el almacén de la sucursal
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

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Jobs\DeductSoldItems;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda a la cola el descuento de lo vendido.
 *
 * ## El oyente sólo ENCOLA, y ahí está todo el diseño
 *
 * Lo que hace falta que sea rápido es esto; lo que puede tardar es el job. Si el oyente hiciera el trabajo, un platillo
 * con receta de tres niveles se resolvería dentro de la petición del cobro — y §6.2 dice que el POS nunca se bloquea por
 * inventario.
 *
 * ## Y ni siquiera encolar puede tumbar el cobro
 *
 * La cuenta ya está pagada cuando esto corre (después del commit). Si la cola estuviera caída, el fallo se registra y
 * no se propaga: alguien pagó, tiene su cambio en la mano, y decirle que no se pudo sería mentirle. La reparación es
 * re-despachar el evento, que no duplica nada porque el job es idempotente.
 *
 * ## Sin items, no se encola nada
 *
 * Una cuenta sin items vendibles —todos cancelados— no tiene qué descontar, y un job vacío en la cola es ruido que
 * alguien acabará investigando.
 */
final readonly class DeductSaleFromInventory
{
    public function handle(PosAccountPaid $event): void
    {
        if ($event->items === []) {
            return;
        }

        try {
            DeductSoldItems::dispatch(
                $event->tenantId,
                $event->branchId,
                $event->accountUlid,
                $event->items,
            );
        } catch (Throwable $e) {
            Log::error('No se pudo encolar el descuento de inventario de una venta.', [
                'tenant_id' => $event->tenantId,
                'account' => $event->accountUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

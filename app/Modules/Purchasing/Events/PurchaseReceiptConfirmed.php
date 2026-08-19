<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se confirmó una recepción de compra: la mercancía entró (§3.2, §4).
 *
 * ## Por qué un evento y no una llamada directa
 *
 * Porque `Purchasing` **no puede escribir en `Inventory` ni en `Costing`** — es la regla de fronteras de ADR-001, la
 * misma por la que el POS jamás escribe en finanzas. Confirmar una recepción tiene tres efectos en tres módulos
 * distintos, y cada uno lo aplica quien es dueño de su tabla:
 *
 *   1. `Inventory` registra un movimiento por línea, creando los lotes que hagan falta.
 *   2. `Costing` captura el costo con `origin = purchase` — el valor del enum que existe desde la Iteración 2 y
 *      llevaba una iteración entera esperando este momento.
 *   3. `Purchasing` deja la observación de precio en su propio historial. Ése sí lo hace él, porque la tabla es suya.
 *
 * ## Síncrono, y con llave de idempotencia
 *
 * Se despacha **después del commit** y los oyentes corren en la misma petición. No en cola, a diferencia del descuento
 * por venta: quien recibe mercancía tiene la caja delante y necesita ver el saldo actualizado para decidir si la mete
 * al estante. La asincronía de §6.2 es para que el POS no se bloquee, y una recepción no es el POS.
 *
 * Los movimientos llevan llave de idempotencia por línea, así que un reintento no duplica nada — que es lo que hace
 * seguro volver a despachar el evento si un oyente falló.
 */
final class PurchaseReceiptConfirmed
{
    use Dispatchable;

    public function __construct(public readonly PurchaseReceipt $receipt) {}
}

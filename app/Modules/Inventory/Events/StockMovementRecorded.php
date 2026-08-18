<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se registró un movimiento de inventario y el saldo ya está actualizado.
 *
 * Se emite **fuera** de la transacción, a propósito: quien escuche no debe poder abortar la escritura del
 * kardex, y un listener lento no tiene por qué mantener abierto el lock del saldo. La consecuencia es que un
 * suscriptor ve un movimiento que ya está firme, que es lo que quiere para reaccionar.
 *
 * Hoy **nadie lo escucha**, y eso es correcto. Se emite desde el primer día porque los suscriptores previstos
 * ya se conocen y todos llegan después:
 *
 *   - **Mínimos y reorden** (P6, diferido a Reportes): «este saldo bajó del mínimo».
 *   - **Tiempo real** (Iteración 6): refrescar la pantalla de un almacén sin que el usuario recargue.
 *   - **Agotar y caducar lotes** (paso 3): un lote cuyo saldo llega a cero pasa a `depleted`.
 *
 * Emitirlo después, cuando ya hubiera código moviendo existencias sin avisar, obligaría a revisar cada
 * llamador para no dejar movimientos silenciosos.
 */
final readonly class StockMovementRecorded
{
    use Dispatchable;

    public function __construct(public StockMovement $movement) {}
}

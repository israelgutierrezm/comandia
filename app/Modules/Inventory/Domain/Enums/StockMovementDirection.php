<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Dirección de un movimiento de inventario.
 *
 * ## Por qué existe, en lugar de una cantidad con signo
 *
 * `quantity` es **siempre positiva** y la dirección viaja aparte. Una cantidad con signo parece más
 * compacta y es una trampa: un `SUM(quantity)` que no mire el signo devuelve un número **plausible y
 * equivocado**, y ese error no revienta en ningún sitio — se acumula en un reporte que nadie sospecha.
 *
 * Con la dirección explícita, cualquier suma tiene que decidir qué hace con las entradas y con las salidas.
 * Es una decisión que el código obliga a tomar en voz alta.
 */
enum StockMovementDirection: string
{
    case In = 'in';

    case Out = 'out';

    /** El signo con el que este movimiento afecta al saldo: `+1` o `-1`. */
    public function sign(): int
    {
        return $this === self::In ? 1 : -1;
    }

    public function label(): string
    {
        return $this === self::In ? 'Entrada' : 'Salida';
    }
}

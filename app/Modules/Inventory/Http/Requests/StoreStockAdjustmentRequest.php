<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Enums\StockMovementKind;

/**
 * Ajuste manual: el sistema dice 10 y hay 8, y no se sabe por qué.
 *
 * Es la confesión de un descuadre, no una operación del negocio, y por eso es el único de los tres que exige
 * **dirección explícita** —el signo es la información— y **nota escrita**.
 *
 * El ajuste formal de un conteo físico (D24) NO pasa por aquí: lo genera el cierre del conteo con el conteo
 * como documento origen, así que su explicación es el conteo mismo.
 */
final class StoreStockAdjustmentRequest extends StoreStockMovementRequest
{
    public function movementKind(): StockMovementKind
    {
        return StockMovementKind::ManualAdjustment;
    }
}

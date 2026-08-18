<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Enums\StockMovementKind;

/**
 * Salida manual de inventario: salió algo que no fue venta ni merma.
 *
 * Consumo interno, se lo llevó el dueño, se usó para una degustación. No es una merma —eso tiene su propio
 * catálogo de motivos y su umbral de autorización (D27)— y no es un ajuste, porque **se sabe** a dónde fue.
 */
final class StoreStockExitRequest extends StoreStockMovementRequest
{
    public function movementKind(): StockMovementKind
    {
        return StockMovementKind::ManualExit;
    }
}

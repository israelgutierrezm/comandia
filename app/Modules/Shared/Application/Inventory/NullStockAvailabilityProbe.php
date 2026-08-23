<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Inventory;

use App\Modules\Shared\Domain\Contracts\StockAvailabilityProbe;

/**
 * Null-object de {@see StockAvailabilityProbe}: sin `Inventory` resolviendo la sonda, la tienda asume que HAY existencia y
 * muestra el artículo. Preferible a ocultar el catálogo entero por un fallo de infraestructura.
 */
final class NullStockAvailabilityProbe implements StockAvailabilityProbe
{
    public function hasStock(int $articleId, int $branchId): bool
    {
        return true;
    }
}

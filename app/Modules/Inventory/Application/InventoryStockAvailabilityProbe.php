<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Contracts\StockAvailabilityProbe;

/**
 * Implementación de {@see StockAvailabilityProbe}: hay existencia si la suma de la proyección de existencias sobre los
 * almacenes de la sucursal es positiva. `Inventory` implementa el contrato del kernel; `Ecommerce` lo consume sin conocer
 * a `Inventory`.
 */
final class InventoryStockAvailabilityProbe implements StockAvailabilityProbe
{
    public function hasStock(int $articleId, int $branchId): bool
    {
        $warehouseIds = Warehouse::query()->where('branch_id', $branchId)->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return false;
        }

        $total = ArticleStock::query()
            ->where('article_id', $articleId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->sum('quantity');

        // Comparación exacta (bcmath): `quantity` es DECIMAL(12,4) y puede ser negativa (§6.2).
        return bccomp((string) $total, '0', 4) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Reporting;

use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Shared\Domain\Reporting\Dimension;
use App\Modules\Shared\Domain\Reporting\FilterSpec;
use App\Modules\Shared\Domain\Reporting\Measure;
use App\Modules\Shared\Domain\Reporting\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mermas por motivo, artículo o almacén (§6.2, D27, D168).
 *
 * La merma NO es una tabla propia: es un movimiento del kardex con `kind = waste` y un motivo (`waste_reason_id`). El
 * índice `(tenant, waste_reason_id, occurred_at)` se puso desde el diseño del kardex justo para este reporte. La declara
 * `Inventory` (dueño de `stock_movements`); el motor no toca la tabla.
 *
 * ## Alcance por sucursal vía almacén
 *
 * El kardex no lleva `branch_id`: la sucursal sale del almacén (`warehouses.branch_id`). Un almacén central o de tránsito
 * tiene `branch_id` NULL, así que sus mermas quedan fuera del alcance por sucursal —un almacén central no es una
 * sucursal—; es una limitación consciente de v1 (la mayoría de la merma ocurre en almacenes de sucursal).
 */
final class WasteReport implements ReportDefinition
{
    public function key(): string
    {
        return 'inventory.waste';
    }

    public function label(): string
    {
        return 'Mermas';
    }

    public function permission(): string
    {
        return 'inventory.kardex.view';
    }

    public function baseQuery(): Builder
    {
        return StockMovement::query()
            ->join('warehouses', 'warehouses.id', '=', 'stock_movements.warehouse_id')
            ->leftJoin('waste_reasons', 'waste_reasons.id', '=', 'stock_movements.waste_reason_id')
            ->leftJoin('articles', 'articles.id', '=', 'stock_movements.article_id')
            ->where('stock_movements.kind', 'waste');
    }

    public function branchColumn(): string
    {
        return 'warehouses.branch_id';
    }

    public function dateColumn(): ?string
    {
        return 'stock_movements.occurred_at';
    }

    public function dimensions(): array
    {
        return [
            'reason' => new Dimension('reason', "COALESCE(waste_reasons.name, 'Sin motivo')", 'Motivo'),
            'article' => new Dimension('article', 'articles.name', 'Artículo'),
            'warehouse' => new Dimension('warehouse', 'warehouses.name', 'Almacén'),
        ];
    }

    public function measures(): array
    {
        return [
            // La cantidad del kardex es SIEMPRE positiva y la dirección la da `kind = waste` (salida); no hay que restar.
            'quantity' => new Measure('quantity', 'ROUND(SUM(stock_movements.quantity), 4)', 'Cantidad', 'quantity'),
            'cost' => new Measure('cost', 'ROUND(SUM(stock_movements.total_cost), 2)', 'Costo', 'money'),
        ];
    }

    public function filters(): array
    {
        return [
            'occurred' => new FilterSpec('occurred', 'stock_movements.occurred_at', 'date_range', 'date'),
            'warehouse' => new FilterSpec('warehouse', 'stock_movements.warehouse_id', 'eq', 'ulid', 'warehouses'),
            'reason' => new FilterSpec('reason', 'stock_movements.waste_reason_id', 'eq', 'ulid', 'waste_reasons'),
        ];
    }

    public function groupings(): array
    {
        return ['reason', 'article', 'warehouse'];
    }

    public function defaultGrouping(): array
    {
        return ['reason'];
    }
}

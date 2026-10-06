<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;

/**
 * Qué consume una línea vendida —del POS o de la tienda— y de qué almacén sale (§6.2).
 *
 * Lo comparten la VENTA (`DeductSoldItems`) y la MERMA de lo que se canceló ya preparado (`RegisterPosCancellationWaste`,
 * D375), y por eso vive aquí y no en cada trabajo: si tirar un platillo consumiera distinto de venderlo, el inventario
 * tendría dos verdades sobre el mismo plato.
 *
 * ## Qué se consume
 *
 * - **producible** → se explota su receta con `ResolveProductionConsumption`, que ya aplica rendimiento y conversión de
 *   unidades. Es el mismo resolutor que usa la producción, a propósito.
 * - **inventariable y no producible** → se consume él mismo.
 * - **ninguna de las dos** → nada, y es legítimo: un servicio, algo que el negocio no controla por existencias.
 *
 * ## De qué almacén
 *
 * Del almacén del ÁREA que lo preparó; sin área —la cerveza que el mesero saca de la nevera—, del almacén de la
 * sucursal. Es lo que hace que un conteo por área cuadre.
 */
final readonly class PosItemConsumption
{
    public function __construct(private ResolveProductionConsumption $recipes) {}

    /**
     * @param  numeric-string  $quantity
     * @return list<array{0: Article, 1: numeric-string}>
     */
    public function componentsOf(Article $article, string $quantity): array
    {
        if ($article->is_producible) {
            return array_map(
                fn ($consumo): array => [$consumo->component, $consumo->quantityInBaseUnit],
                $this->recipes->forQuantity($article, $quantity),
            );
        }

        if ($article->is_inventoriable) {
            return [[$article, $quantity]];
        }

        return [];
    }

    /**
     * El almacén de un área, o `null` si no tiene área o el área no tiene almacén.
     */
    public function areaWarehouse(?int $preparationAreaId): ?Warehouse
    {
        if ($preparationAreaId === null) {
            return null;
        }

        $area = PreparationArea::query()->whereKey($preparationAreaId)->first();

        return $area?->warehouse_id === null
            ? null
            : Warehouse::query()->whereKey($area->warehouse_id)->first();
    }

    /**
     * El almacén de la sucursal: el que tiene marcado por omisión, o el primero suyo.
     */
    public function branchWarehouse(int $branchId): ?Warehouse
    {
        $branch = Branch::query()->whereKey($branchId)->first();

        if ($branch?->default_warehouse_id !== null) {
            return Warehouse::query()->whereKey($branch->default_warehouse_id)->first();
        }

        return Warehouse::query()->where('branch_id', $branchId)->orderBy('id')->first();
    }
}

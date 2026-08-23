<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Contracts;

/**
 * «¿Hay existencia de este artículo en esta sucursal AHORA?»
 *
 * La tienda en línea SÍ respeta stock (ADR-007), a diferencia del POS. Para decidir si oculta un artículo, lo marca
 * «agotado» o lo vende, `Ecommerce` necesita saber si hay existencia en la sucursal elegida. Lo pregunta por esta sonda del
 * kernel: `Inventory` la implementa sumando su proyección de existencias sobre los almacenes de la sucursal; `Ecommerce`
 * la consume sin nombrar a `Inventory`.
 *
 * El null-object devuelve `true` (hay existencia): si la sonda no se puede resolver, la tienda muestra el artículo en vez
 * de ocultarlo por un fallo de infraestructura. La política de stock del artículo sigue mandando —quien elige `hide` o
 * `mark_out_of_stock` acepta que dependan de esta señal—.
 */
interface StockAvailabilityProbe
{
    /**
     * ¿Existe cantidad positiva del artículo en los almacenes de la sucursal? Recibe ids internos (el llamador ya tiene
     * el artículo y la sucursal resueltos) y devuelve un primitivo: `Ecommerce` jamás toca un modelo de `Inventory`.
     */
    public function hasStock(int $articleId, int $branchId): bool;
}

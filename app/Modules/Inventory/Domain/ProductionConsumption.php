<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Catalog\Infrastructure\Models\Article;

/**
 * Un consumo resuelto: qué componente, cuánto en su unidad base, y de dónde salió esa cantidad.
 *
 * Los tres últimos campos son el **snapshot de la línea de receta** y no adorno: `quantityInBaseUnit` dice cuánto se
 * saca del almacén, y `recipeQuantity` con su unidad y su rendimiento dicen **por qué esa cantidad**. Sin ellos, quien
 * revisara un consumo raro no podría distinguir «la receta pedía de más» de «alguien la cambió después».
 */
final readonly class ProductionConsumption
{
    /**
     * @param  numeric-string  $quantityInBaseUnit  ya escalado y con el rendimiento aplicado
     * @param  numeric-string  $recipeQuantity  como estaba escrita en la receta, sin escalar
     * @param  numeric-string  $yieldPercent
     */
    public function __construct(
        public Article $component,
        public string $quantityInBaseUnit,
        public string $recipeQuantity,
        public int $recipeUnitId,
        public string $yieldPercent,
    ) {}
}

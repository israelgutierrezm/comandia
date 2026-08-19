<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un insumo consumido por una producción, con el snapshot de lo que la receta pedía.
 *
 * @property int $id
 * @property string $recipe_quantity
 * @property string $yield_percent
 * @property string $consumed_quantity
 * @property string|null $unit_cost_at_production
 */
final class ProductionOrderLine extends DomainModel
{
    protected $table = 'production_order_lines';

    protected $fillable = [
        'production_order_id',
        'component_article_id',
        'lot_id',
        'recipe_quantity',
        'recipe_unit_id',
        'yield_percent',
        'consumed_quantity',
        'unit_cost_at_production',
        'movement_id',
    ];

    /** @return BelongsTo<ProductionOrder, $this> */
    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'component_article_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function recipeUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'recipe_unit_id');
    }

    /** @return BelongsTo<ArticleLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ArticleLot::class, 'lot_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    /**
     * Lo que costó este insumo en esta producción. `null` si no tenía costo capturado.
     *
     * @return numeric-string|null
     */
    public function lineCost(): ?string
    {
        if ($this->unit_cost_at_production === null) {
            return null;
        }

        return Decimal::round(bcmul($this->consumed_quantity, $this->unit_cost_at_production, 6), 2);
    }
}

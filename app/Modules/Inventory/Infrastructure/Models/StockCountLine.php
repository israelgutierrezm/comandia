<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la hoja de conteo.
 *
 * `variance` y `lot_key` son columnas **generadas** por la base de datos: no se escriben nunca desde aquí, y por
 * eso no están en `$fillable`. Que la diferencia la calcule la base es lo que garantiza que el reporte, el umbral
 * de autorización y el ajuste del kardex hablen de la misma cifra.
 *
 * @property int $id
 * @property string $expected_quantity
 * @property string|null $counted_quantity
 * @property string|null $variance
 * @property string|null $unit_cost_at_count
 */
final class StockCountLine extends DomainModel
{
    protected $table = 'stock_count_lines';

    protected $fillable = [
        'stock_count_id',
        'article_id',
        'lot_id',
        'expected_quantity',
        'counted_quantity',
        'unit_cost_at_count',
        'adjustment_movement_id',
    ];

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<ArticleLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ArticleLot::class, 'lot_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function adjustmentMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'adjustment_movement_id');
    }

    /**
     * Las líneas que generan ajuste al cerrar: contadas y con diferencia.
     *
     * `whereNotNull('counted_quantity')` es redundante con `variance != 0` —una diferencia nula implica que no se
     * contó— y se deja escrito porque la intención importa más que la brevedad: sin contar no se ajusta, y quien
     * lea esta consulta no tiene por qué deducirlo del comportamiento de `NULL` en aritmética SQL.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithVariance(Builder $query): Builder
    {
        return $query
            ->whereNotNull('counted_quantity')
            ->where('variance', '!=', 0);
    }

    public function wasCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /**
     * La diferencia valuada al costo congelado, con signo. `null` si no se contó o no había costo.
     *
     * @return numeric-string|null
     */
    public function varianceValue(): ?string
    {
        if ($this->variance === null || $this->unit_cost_at_count === null) {
            return null;
        }

        return Decimal::round(bcmul($this->variance, $this->unit_cost_at_count, 6), 2);
    }
}

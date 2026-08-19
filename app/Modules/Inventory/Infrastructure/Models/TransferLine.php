<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de transferencia con sus tres cantidades.
 *
 * `transit_difference` y `lot_key` las genera la base: no se escriben desde aquí y no están en `$fillable`.
 *
 * @property int $id
 * @property string $requested_quantity
 * @property string|null $shipped_quantity
 * @property string|null $received_quantity
 * @property string|null $transit_difference
 */
final class TransferLine extends DomainModel
{
    protected $table = 'transfer_lines';

    protected $fillable = [
        'transfer_id',
        'article_id',
        'lot_id',
        'requested_quantity',
        'shipped_quantity',
        'received_quantity',
    ];

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
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

    /**
     * Las que se enviaron: sólo ésas mueven mercancía.
     *
     * Una línea pedida y no enviada no es un error — es la respuesta «no había» — y no debe producir ningún
     * movimiento ni contar como diferencia en tránsito, porque nunca subió al camión.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeShipped(Builder $query): Builder
    {
        return $query->whereNotNull('shipped_quantity')->where('shipped_quantity', '>', 0);
    }

    /**
     * Las que llegaron incompletas.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithTransitDifference(Builder $query): Builder
    {
        return $query->whereNotNull('received_quantity')->where('transit_difference', '>', 0);
    }

    public function wasShipped(): bool
    {
        return $this->shipped_quantity !== null && bccomp($this->shipped_quantity, '0', 4) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A qué apunta una promoción: un artículo o una categoría (exactamente uno, por CHECK).
 *
 * No lleva ULID público: no se expone por sí mismo, sólo como parte de su promoción.
 */
final class PromotionTarget extends DomainModel
{
    protected $table = 'promotion_targets';

    protected $fillable = [
        'promotion_id',
        'article_id',
        'article_category_id',
    ];

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * El artículo apuntado, si el objetivo es un artículo. Existe para exponer su ULID —nunca el id interno (D3)— cuando
     * el expediente de la promoción vuelve al cliente para editarla.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * La categoría apuntada, si el objetivo es una categoría.
     *
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'article_category_id');
    }
}

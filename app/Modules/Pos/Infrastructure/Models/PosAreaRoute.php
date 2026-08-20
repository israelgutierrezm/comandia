<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Domain\Exceptions\PosAreaRouteException;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué área prepara qué, en una sucursal (D240).
 */
final class PosAreaRoute extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_area_routes';

    protected $fillable = [
        'branch_id',
        'article_id',
        'article_category_id',
        'preparation_area_id',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // El CHECK de la base ya lo impide, pero un error de constraint sale como 500 y no dice qué hacer. Aquí sale
        // como invariante de dominio con un mensaje que explica la regla.
        static::saving(function (self $route): void {
            $tieneArticulo = $route->article_id !== null;
            $tieneCategoria = $route->article_category_id !== null;

            if ($tieneArticulo === $tieneCategoria) {
                throw PosAreaRouteException::target();
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'article_category_id');
    }

    /**
     * @return BelongsTo<PreparationArea, $this>
     */
    public function preparationArea(): BelongsTo
    {
        return $this->belongsTo(PreparationArea::class);
    }

    /** ¿Es la regla precisa de un artículo, o la general de una categoría? */
    public function isArticleOverride(): bool
    {
        return $this->article_id !== null;
    }
}

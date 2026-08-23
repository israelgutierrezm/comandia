<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La publicación de un artículo (Iteración 8, Tanda A): lo que la vitrina agrega al artículo del Core sin duplicarlo
 * (ADR-007). Una por artículo. La galería vive en `article_images`, ligada por el mismo `article_id`.
 */
final class ArticlePublication extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'article_publications';

    protected $fillable = [
        'article_id',
        'long_description',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * La galería del mismo artículo. Se liga por `article_id` (no por la PK de la publicación): las imágenes son del
     * artículo, y la publicación y la galería son dos caras de lo mismo.
     *
     * @return HasMany<ArticleImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'article_id', 'article_id')->orderBy('sort_order');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de artículos, de dos niveles exactos (D18).
 *
 * `level` es redundante con `parent_id` a propósito, y un CHECK impide que se contradigan. Lo que el
 * CHECK no puede hacer —porque no consulta otras filas— es impedir que una subcategoría apunte a otra
 * subcategoría; eso lo impone {@see self::assertValidParent()} y lo verifica una prueba.
 *
 * @property int|null $parent_id
 * @property int $level
 * @property string $name
 * @property CatalogStatus $status
 */
final class ArticleCategory extends DomainModel
{
    use HasPublicUlid;

    public const MAX_LEVEL = 2;

    protected $table = 'article_categories';

    protected $fillable = [
        'parent_id',
        'level',
        'name',
        'sort_order',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * El nivel que le corresponde a una categoría según tenga padre o no.
     *
     * Existe para que el servicio de aplicación no lo calcule a mano en dos sitios y se
     * desincronicen: `level` y `parent_id` tienen que contar la misma historia o el CHECK rechaza
     * la fila.
     */
    public static function levelFor(?self $parent): int
    {
        return $parent === null ? 1 : $parent->level + 1;
    }

    /**
     * ¿Puede esta categoría ser padre?
     *
     * Sólo las de nivel 1. D18 dice dos niveles; un tercero exige una decisión de producto y no una
     * migración.
     */
    public function canBeParent(): bool
    {
        return $this->level < self::MAX_LEVEL;
    }
}

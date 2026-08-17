<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La cabecera de una receta (D16).
 *
 * `output_quantity` es lo que hace posible el costeo en cascada: una receta de salsa cuesta $100 y
 * rinde 2 L, así que el litro cuesta $50 y ése es el número que entra en la receta de las enchiladas.
 *
 * Todavía no tiene `modifier_id`: `modifiers` es el paso 10 de la iteración. Ver la migración.
 *
 * @property numeric-string $output_quantity
 * @property CatalogStatus $status
 */
final class Recipe extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'recipes';

    protected $fillable = [
        'article_id',
        'output_quantity',
        'output_unit_id',
        'notes',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'output_quantity' => '1.0000',
    ];

    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,

            // `output_quantity` sin cast a float: es el DIVISOR del costo total de la receta, así que
            // entra en aritmética `bcmath` (§7, P3).
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function outputUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'output_unit_id');
    }

    /**
     * @return HasMany<RecipeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

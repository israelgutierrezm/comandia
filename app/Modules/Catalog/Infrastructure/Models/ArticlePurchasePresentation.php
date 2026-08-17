<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Presentación de compra de un artículo (D22): "Costal de 25 kg", "Caja con 24".
 *
 * `quantity_in_base_unit` está en la unidad base del artículo, así que la presentación es un
 * múltiplo y no otra unidad. Es la única forma legítima de expresar "una caja tiene 24 piezas": esa
 * afirmación sólo es cierta por artículo, y por eso las unidades no permiten convertir entre
 * dimensiones.
 *
 * @property string $name
 * @property numeric-string $quantity_in_base_unit
 * @property string|null $barcode
 * @property bool $is_default
 * @property CatalogStatus $status
 */
final class ArticlePurchasePresentation extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'article_purchase_presentations';

    protected $fillable = [
        'article_id',
        'name',
        'quantity_in_base_unit',
        'barcode',
        'is_default',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'status' => CatalogStatus::class,

            // `quantity_in_base_unit` sin cast a float: divide el costo de la presentación para
            // obtener el costo unitario, así que entra en aritmética `bcmath`.
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

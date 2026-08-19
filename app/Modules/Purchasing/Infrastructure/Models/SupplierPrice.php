<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una observación de precio de proveedor (D26). **Inmutable** (§7).
 *
 * Se corrige agregando otra observación, no editando ésta: si el precio se capturó mal, lo cierto es que hubo un error
 * de captura ese día, y borrarlo hace que el historial mienta sobre lo que se sabía entonces. Es el mismo trato que el
 * historial de costos.
 *
 * @property string $unit_price
 * @property SupplierPriceSource $source
 */
final class SupplierPrice extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'supplier_prices';

    protected $fillable = [
        'supplier_id',
        'article_id',
        'presentation_id',
        'unit_price',
        'observed_quantity',
        'observed_price',
        'currency',
        'observed_at',
        'source',
        'purchase_receipt_id',
        'registered_by_membership_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source' => SupplierPriceSource::class,
            'observed_at' => 'immutable_date',

            // A mano, y hace falta: el trait `Immutable` apaga `$timestamps` —sólo se escribe `created_at`, desde la
            // base— y con los timestamps apagados Laravel deja de castear `created_at` por su cuenta. Sin esta línea
            // llega como cadena y el Resource revienta al pedirle un formato. `ArticleCost` ya lo hacía; se me pasó.
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<ArticlePurchasePresentation, $this> */
    public function presentation(): BelongsTo
    {
        return $this->belongsTo(ArticlePurchasePresentation::class, 'presentation_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'registered_by_membership_id');
    }

    /**
     * La observación más reciente primero, desempatada por llave.
     *
     * El desempate hace falta y no es cosmético: varias observaciones del mismo día son lo normal —una factura y una
     * cotización llegan el mismo martes— y sin él «el precio más reciente» lo decidiría MySQL (D182).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('observed_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForArticle(Builder $query, int $articleId): Builder
    {
        return $query->where('article_id', $articleId);
    }
}

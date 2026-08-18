<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\EffectivePricing;
use App\Modules\Catalog\Domain\Enums\ArticleStatus;
use App\Modules\Catalog\Domain\Exceptions\ArticleInvariantException;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El artículo unificado (D17).
 *
 * Cuatro capacidades independientes y combinables. No es flexibilidad gratuita: en un restaurante la
 * misma cosa es varias a la vez. Una cerveza en botella se vende, se inventaría y es insumo de una
 * michelada; una salsa preparada en tandas se inventaría, es insumo y es producible.
 *
 * ## Este modelo NO conoce su costo
 *
 * El costo —historial y proyección— pertenece al módulo `Costing` (P1 de la Iteración 2). Preguntarle
 * el costo a un artículo desde aquí obligaría a `Catalog` a depender de `Costing`, y el candado de
 * fronteras lo rechazaría. Quien necesite las dos cosas es la capa HTTP, que puede depender de las
 * dos.
 *
 * @property string|null $code
 * @property string $name
 * @property string|null $short_name
 * @property int|null $category_id
 * @property int $base_unit_id
 * @property bool $is_sellable
 * @property bool $is_inventoriable
 * @property bool $is_supply
 * @property bool $is_producible
 * @property string|null $base_price
 * @property string|null $markup_percent
 * @property bool $is_available_in_pos
 * @property ArticleStatus $status
 */
final class Article extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'articles';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'category_id',
        'base_unit_id',
        'is_sellable',
        'is_inventoriable',
        'is_supply',
        'is_producible',
        'base_price',
        'markup_percent',
        'is_available_in_pos',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_sellable' => false,
        'is_inventoriable' => false,
        'is_supply' => false,
        'is_producible' => false,
        'is_available_in_pos' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'is_inventoriable' => 'boolean',
            'is_supply' => 'boolean',
            'is_producible' => 'boolean',
            'is_available_in_pos' => 'boolean',
            'status' => ArticleStatus::class,

            // `base_price` y `markup_percent` NO se castean a float: entran en aritmética de costeo
            // con `bcmath` y un float reintroduciría el error que esa decisión evita (§7, P3).
        ];
    }

    /**
     * Invariantes impuestos en el modelo y no sólo en el Form Request.
     *
     * Un Form Request protege el camino HTTP; estas reglas también tienen que valer para seeders,
     * importaciones y servicios internos. La primera importación masiva de catálogo es exactamente
     * donde una regla que sólo vive en la capa HTTP se rompe.
     */
    protected static function booted(): void
    {
        self::saving(function (self $article): void {
            // I2: vendible exige precio.
            if ($article->is_sellable && $article->base_price === null) {
                throw ArticleInvariantException::sellableWithoutPrice();
            }

            // I11 (P11): vendible exige categoría, porque el POS agrupa por categoría.
            if ($article->is_sellable && $article->category_id === null) {
                throw ArticleInvariantException::sellableWithoutCategory();
            }

            // I6, en su forma estricta. El diseño decía "no cambia si el artículo tiene costos,
            // recetas o movimientos", y P1 hace esa versión IMPOSIBLE de imponer desde aquí:
            // averiguar si tiene costos o recetas sería preguntarle a `Costing`, y `Catalog` no
            // puede depender de `Costing`. Averiguar si tiene movimientos sería preguntarle a
            // `Inventory`, que ni existe todavía.
            //
            // Así que la regla que este módulo SÍ puede imponer correctamente es la estricta: la
            // unidad base no cambia nunca. Es más restrictiva que I6 y la salida está en el mensaje
            // de la excepción: archivar y capturar de nuevo.
            if ($article->exists && $article->isDirty('base_unit_id')) {
                throw ArticleInvariantException::baseUnitIsImmutable();
            }
        });
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    /**
     * Etiquetas del artículo.
     *
     * `withPivotValue('tenant_id', …)` no es opcional: `article_tag.tenant_id` es NOT NULL por la
     * Regla A, y `sync()` sólo escribe las dos llaves de la relación. Sin esto, etiquetar un artículo
     * fallaría con un error de columna nula que no dice nada sobre la causa.
     *
     * Es el mismo tropiezo que D82 documentó con el pivote de permisos de Spatie en la Iteración 1:
     * los pivotes de paquetes y de Eloquent no saben nada del multi-tenancy de este proyecto, así que
     * cada uno tiene que declarar cómo se llena su `tenant_id`.
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'article_tag')
            ->withPivotValue('tenant_id', app(TenantContext::class)->id());
    }

    /**
     * @return HasMany<ArticlePurchasePresentation, $this>
     */
    public function purchasePresentations(): HasMany
    {
        return $this->hasMany(ArticlePurchasePresentation::class);
    }

    /**
     * Overrides de precio y disponibilidad por sucursal (§6.1).
     *
     * @return HasMany<ArticleBranchOverride, $this>
     */
    public function branchOverrides(): HasMany
    {
        return $this->hasMany(ArticleBranchOverride::class);
    }

    /**
     * El override de una sucursal concreta, **de entre los ya cargados**.
     *
     * Sobre la colección en memoria y no con una consulta: quien pinta el catálogo de una sucursal precarga
     * los overrides de esa sucursal para todos los artículos, y una consulta aquí convertiría eso en una por
     * fila. Devuelve `null` si la relación no está cargada, que es lo correcto — quien no la cargó no debe
     * recibir "no tiene override" como si fuera un hecho.
     */
    public function loadedOverrideFor(int $branchId): ?ArticleBranchOverride
    {
        if (! $this->relationLoaded('branchOverrides')) {
            return null;
        }

        return $this->branchOverrides->firstWhere('branch_id', $branchId);
    }

    /**
     * El precio y la disponibilidad que aplican en una sucursal, con la cascada resuelta.
     *
     * Sin sucursal devuelve el dato maestro: es el caso de la administración del catálogo, que trabaja sobre
     * el negocio completo.
     */
    public function effectivePricingFor(?int $branchId): EffectivePricing
    {
        $override = $branchId === null ? null : $this->loadedOverrideFor($branchId);

        return EffectivePricing::resolve(
            masterPrice: $this->base_price,
            masterAvailability: $this->is_available_in_pos,
            overridePrice: $override?->price,
            overrideAvailability: $override?->is_available_in_pos,
        );
    }

    // -----------------------------------------------------------------
    // Consultas
    // -----------------------------------------------------------------

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Active->value);
    }

    /**
     * Los artículos que el POS puede ofrecer ahora mismo.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSellableInPos(Builder $query): Builder
    {
        return $query
            ->where('status', ArticleStatus::Active->value)
            ->where('is_sellable', true)
            ->where('is_available_in_pos', true);
    }

    /**
     * Los que pueden ser componente de una receta (invariante I5).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableAsSupply(Builder $query): Builder
    {
        return $query
            ->where('status', ArticleStatus::Active->value)
            ->where('is_supply', true);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Nombre para comanda y botón de POS.
     *
     * Cae al nombre completo si no hay corto: una comanda con el nombre largo es fea; una comanda
     * sin nombre es inservible.
     */
    public function displayName(): string
    {
        return $this->short_name !== null && $this->short_name !== ''
            ? $this->short_name
            : $this->name;
    }

    /**
     * Las cuatro capacidades, para respuestas de API y para pruebas.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'sellable' => $this->is_sellable,
            'inventoriable' => $this->is_inventoriable,
            'supply' => $this->is_supply,
            'producible' => $this->is_producible,
        ];
    }
}

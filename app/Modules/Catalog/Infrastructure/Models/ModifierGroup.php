<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de modificadores con sus reglas de selección (D7).
 *
 * "Término de la carne", "Extras", "Tipo de tortilla". Es del tenant y se **reutiliza** entre artículos: si
 * cada artículo tuviera su copia, editar la regla exigiría editarla una vez por artículo — y garantizaría que
 * se editen todas menos una.
 *
 * Las reglas viven aquí y **no se sobrescriben por artículo** (P8): un artículo que necesita reglas distintas
 * usa un grupo distinto. Permitir override metería una cascada en la validación más caliente del POS, y ahí
 * una regla ambigua es un platillo mal preparado.
 *
 * @property string $name
 * @property bool $is_required
 * @property int $min_selections
 * @property int|null $max_selections
 * @property bool $allows_quantity
 * @property CatalogStatus $status
 */
final class ModifierGroup extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'modifier_groups';

    protected $fillable = [
        'name',
        'is_required',
        'min_selections',
        'max_selections',
        'allows_quantity',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_required' => false,
        'min_selections' => 0,
        'allows_quantity' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'allows_quantity' => 'boolean',
            'min_selections' => 'integer',

            // Nullable a propósito: `null` significa "sin límite", que es distinto de un número alto. Castear a
            // integer convertiría el null en 0 y el grupo pasaría a no admitir ninguna selección.
            'max_selections' => 'integer',

            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return HasMany<Modifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class);
    }

    /**
     * Los artículos que usan este grupo.
     *
     * `withPivotValue('tenant_id', …)` por lo mismo que en `Article::tags()`: la Regla A exige `tenant_id` en el
     * pivote y `sync()` sólo escribe las dos llaves de la relación (D82).
     *
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_modifier_group')
            ->withPivotValue('tenant_id', app(TenantContext::class)->id());
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

    /**
     * ¿Cuántas opciones se pueden elegir como máximo?
     *
     * Devuelve `null` cuando no hay límite. Existe para que el POS no tenga que interpretar el null por su
     * cuenta y acabe con su propia versión de la regla.
     */
    public function hasSelectionLimit(): bool
    {
        return $this->max_selections !== null;
    }
}

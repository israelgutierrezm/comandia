<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una opción de un grupo de modificadores (D7): "Término medio", "Extra queso", "Sin cebolla".
 *
 * `extra_price` va con IVA incluido, como todo precio del sistema (D30), y **no admite negativos** (P14): un
 * modificador que resta es un descuento, y los descuentos tienen permiso, motivo y actor propios (§6.3).
 * Permitirlos aquí sería una puerta para descontar sin dejar rastro.
 *
 * ## Puede tener receta propia
 *
 * §6.1 pide "impacto en receta por unidad": "extra queso" consume 30 g de queso. Esa receta vive en `recipes`
 * con `modifier_id`, y este modelo **no la conoce** — es del módulo `Costing`, y `Catalog` no puede depender de
 * él (P1). Igual que el artículo no conoce su costo.
 *
 * @property string $name
 * @property numeric-string $extra_price
 * @property CatalogStatus $status
 */
final class Modifier extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'modifiers';

    protected $fillable = [
        'modifier_group_id',
        'name',
        'extra_price',
        'sort_order',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
        'extra_price' => '0.00',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => CatalogStatus::class,

            // `extra_price` sin cast a float: es un monto y entra en la suma del precio de la línea (§7, P3).
        ];
    }

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
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
     * ¿Suma algo al precio de la línea?
     *
     * "Sin cebolla" cuesta 0 y sigue siendo un modificador válido: se imprime en comanda y puede tener impacto
     * en receta —quitar cebolla no la devuelve al almacén, pero no consumirla sí cambia el costo—.
     */
    public function isPaid(): bool
    {
        return bccomp($this->extra_price, '0', 2) > 0;
    }
}

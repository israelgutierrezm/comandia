<?php

declare(strict_types=1);

namespace App\Modules\Floor\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Un plano del salón (D34).
 *
 * Múltiples por sucursal desde el modelo, aunque en esta iteración se opere con uno: un negocio con terraza que cierra
 * en temporada de lluvias quiere dos planos, no mover mesas de zona.
 *
 * @property OperationalStatus $status
 */
final class FloorPlan extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'floor_plans';

    protected $fillable = ['branch_id', 'name', 'is_default', 'status', 'canvas_width', 'canvas_height'];

    protected $attributes = [
        'status' => 'active',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'is_default' => 'boolean',

            // Cadenas y no float: son coordenadas en centímetros con dos decimales, y el punto flotante convertiría
            // un lienzo de 1200.00 en 1199.9999999. Es la misma razón por la que el dinero viaja como cadena.
            'canvas_width' => 'string',
            'canvas_height' => 'string',

            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<FloorZone, $this>
     */
    public function zones(): HasMany
    {
        // ORDENADAS por `sort_order`, que es para lo que existe la columna. Sin el orden explícito, MySQL devuelve las
        // zonas como quiera —me salieron alfabéticas— y la pantalla del salón las pintaría en un orden distinto cada
        // vez que cambiara el plan de ejecución. Lo encontró una prueba que esperaba el orden de creación.
        return $this->hasMany(FloorZone::class, 'floor_plan_id')->orderBy('sort_order');
    }

    /**
     * Todas las mesas del plano, a través de sus zonas.
     *
     * Existe para que el editor pida el plano completo en UNA petición. Cruzar zonas y mesas en el cliente pintaría un
     * plano a medias mientras la segunda llamada viaja.
     *
     * Ordenadas por código: el editor lista las mesas junto al dibujo, y un orden que cambia entre recargas hace que la
     * lista salte bajo el cursor de quien la está usando.
     *
     * @return HasManyThrough<RestaurantTable, FloorZone, $this>
     */
    public function tables(): HasManyThrough
    {
        return $this->hasManyThrough(
            RestaurantTable::class,
            FloorZone::class,
            'floor_plan_id',
            'floor_zone_id',
        )->orderBy('restaurant_tables.code');
    }

    /**
     * Los elementos decorativos del plano —muros, puertas, rótulos— (ADR-011). Directos al plano, no a través de zonas:
     * un muro no pertenece a una zona. Ordenados por apilado; se dibujan detrás de las mesas.
     *
     * @return HasMany<FloorElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(FloorElement::class, 'floor_plan_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

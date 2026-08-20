<?php

declare(strict_types=1);

namespace App\Modules\Floor\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una zona del plano: terraza, salón, barra.
 *
 * Existe para agrupar mesas y para que la pantalla de piso pueda mostrarlas por secciones. No lleva estado propio: el
 * estado es de la mesa, y una «zona ocupada» no significa nada operativo.
 */
final class FloorZone extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'floor_zones';

    protected $fillable = ['floor_plan_id', 'name', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<FloorPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class, 'floor_plan_id');
    }

    /**
     * @return HasMany<RestaurantTable, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'floor_zone_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Floor\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = ['branch_id', 'name', 'is_default', 'status'];

    protected $attributes = [
        'status' => 'active',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'is_default' => 'boolean',
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

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sucursal.
 *
 * `timezone` es columna y no llave de configuración: no es un toggle de D20, es un
 * dato estructural que necesitan las consultas que calculan "el día" de un corte.
 * Resolverlo por cascada de configuración en cada reporte sería absurdo.
 *
 * @property OperationalStatus $status
 * @property string $timezone
 */
final class Branch extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'branches';

    protected $fillable = [
        'code',
        'name',
        'status',
        'timezone',
        'default_warehouse_id',
        'street',
        'exterior_number',
        'interior_number',
        'neighborhood',
        'municipality',
        'state',
        'postal_code',
        'country',
        'phone',
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Almacenes de esta sucursal. NO incluye los centrales: ésos no pertenecen a
     * ninguna sucursal (D11).
     *
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    /**
     * @return HasMany<PreparationArea, $this>
     */
    public function preparationAreas(): HasMany
    {
        return $this->hasMany(PreparationArea::class);
    }

    /**
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
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
        return $query->where('status', OperationalStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Serie de foliación por defecto de esta sucursal (§7).
     */
    public function defaultDocumentSeries(): string
    {
        return $this->code;
    }
}

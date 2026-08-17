<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Almacén, de sucursal o central (D11).
 *
 * Un almacén NO cuenta como sucursal para el cobro
 * (ESPECIFICACIÓN_MAESTRA §2).
 *
 * @property OperationalStatus $status
 * @property WarehouseKind $kind
 */
final class Warehouse extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'warehouses';

    protected $fillable = ['branch_id', 'kind', 'code', 'name', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => WarehouseKind::class,
            'status' => OperationalStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * NULL en los almacenes centrales.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Áreas de preparación que consumen de este almacén.
     *
     * @return HasMany<PreparationArea, $this>
     */
    public function preparationAreas(): HasMany
    {
        return $this->hasMany(PreparationArea::class);
    }

    public function isCentral(): bool
    {
        return $this->kind === WarehouseKind::Central;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Almacenes centrales del tenant: surten a todas las sucursales.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCentral(Builder $query): Builder
    {
        return $query->where('kind', WarehouseKind::Central->value);
    }

    /**
     * Almacenes alcanzables desde una sucursal: los propios más los centrales.
     *
     * Esta es la definición operativa que usa el inventario, y vive aquí para que
     * ningún módulo la reinvente a medias — olvidar los centrales sería el error
     * natural (D11).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReachableFromBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(function (Builder $query) use ($branchId): void {
            $query->where('branch_id', $branchId)
                ->orWhere('kind', WarehouseKind::Central->value);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Terminal de punto de venta.
 *
 * Aquí la terminal es sólo una entidad de la organización que el contexto puede
 * validar contra el header `X-Terminal`. La sesión de caja y el emparejamiento con
 * el hardware son del POS (Iteración 4).
 *
 * @property OperationalStatus $status
 */
final class Terminal extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'terminals';

    protected $fillable = ['branch_id', 'code', 'name', 'status'];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'last_seen_at' => 'immutable_datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveInBranch(Builder $query, int $branchId): Builder
    {
        return $query
            ->where('branch_id', $branchId)
            ->where('status', OperationalStatus::Active->value);
    }

    /**
     * Marca actividad de la terminal.
     *
     * Escritura deliberadamente sin tocar `updated_at`: es telemetría de
     * conectividad, no un cambio de la entidad, y no debe ensuciar la fecha de
     * modificación que ve el administrador ni disparar auditoría.
     */
    public function touchLastSeen(): void
    {
        $this->newQuery()
            ->whereKey($this->getKey())
            ->update(['last_seen_at' => now()]);
    }
}

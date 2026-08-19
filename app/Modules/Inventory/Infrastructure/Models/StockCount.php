<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\StockCountStatus;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un conteo físico (D24, §6.2).
 *
 * Un conteo **cerrado es inmutable**, pero la tabla no lleva el trait `Immutable`: mientras está en captura sus
 * líneas se escriben una y otra vez, que es en lo que consiste contar. La inmutabilidad es del estado cerrado, no
 * de la tabla, y se impone aquí —en `updating`— porque es una regla del documento y no del esquema.
 *
 * @property int $id
 * @property StockCountStatus $status
 */
final class StockCount extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'stock_counts';

    protected $fillable = [
        'warehouse_id',
        'status',
        'started_by_membership_id',
        'closed_by_membership_id',
        'authorized_by_membership_id',
        'started_at',
        'closed_at',
        'variance_value',
        'variance_value_absolute',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Un conteo cerrado o cancelado no se toca más. Corregir un conteo mal hecho es **hacer otro**, y esa no
        // es una limitación incómoda: es lo que hace que las diferencias aplicadas al kardex —que es inmutable—
        // tengan siempre un documento que las explique tal como estaba cuando se aplicaron.
        //
        // Se comprueba sobre el valor ORIGINAL, no el actual: si se leyera el actual, la propia transición a
        // `closed` se rechazaría a sí misma.
        self::updating(function (self $count): void {
            // `getRawOriginal` y no `getOriginal`: con el cast de enum, `getOriginal` devuelve el enum ya construido y
            // compararlo con una cadena nunca sería igual — la guarda rechazaría hasta la propia transición a
            // cerrado.
            $original = $count->getRawOriginal('status');

            if ($original !== null && $original !== StockCountStatus::Counting->value) {
                throw new \RuntimeException(
                    'Un conteo cerrado o cancelado no se puede modificar. Para corregirlo, haz otro conteo.'
                );
            }
        });
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'stock_count_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'started_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'closed_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /**
     * El conteo abierto de un almacén, si hay.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpenIn(Builder $query, int $warehouseId): Builder
    {
        return $query
            ->where('warehouse_id', $warehouseId)
            ->where('status', StockCountStatus::Counting->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}

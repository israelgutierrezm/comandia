<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una transferencia entre almacenes (D25, §6.2).
 *
 * El documento **no es inmutable**: es una máquina de estados y su razón de existir es cambiar. Lo que sí es
 * inmutable es la ruta y el folio — cambiar el destino de una transferencia ya enviada reinterpretaría movimientos
 * de kardex que ya existen, y cambiar el folio rompería la garantía de §7.
 *
 * @property int $id
 * @property TransferStatus $status
 */
final class Transfer extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'transfers';

    protected $fillable = [
        'origin_warehouse_id',
        'destination_warehouse_id',
        'status',
        'folio_branch_id',
        'series',
        'folio',
        'requested_by_membership_id', 'requested_at',
        'authorized_by_membership_id', 'authorized_at',
        'prepared_by_membership_id', 'prepared_at',
        'shipped_by_membership_id', 'shipped_at',
        'received_by_membership_id', 'received_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'requested_at' => 'immutable_datetime',
            'authorized_at' => 'immutable_datetime',
            'prepared_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // La ruta y el folio no cambian nunca. La ruta porque los movimientos del kardex ya la citan —y el kardex
        // es inmutable— así que reasignarla reinterpretaría mercancía que ya se movió; el folio porque §7 lo exige
        // consecutivo y sin huecos, y un folio reasignado deja un hueco donde estaba.
        self::updating(function (self $transfer): void {
            foreach (['origin_warehouse_id', 'destination_warehouse_id', 'folio_branch_id', 'series', 'folio'] as $frozen) {
                if ($transfer->isDirty($frozen)) {
                    throw new \RuntimeException(
                        "La transferencia no admite cambiar «{$frozen}»: sus movimientos de inventario ya lo citan. "
                        .'Cancélala y haz otra.'
                    );
                }
            }
        });
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function originWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return HasMany<TransferLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class, 'transfer_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'requested_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'prepared_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'shipped_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'received_by_membership_id');
    }

    /**
     * Las que esperan acción de alguien.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TransferStatus::Requested->value,
            TransferStatus::Authorized->value,
            TransferStatus::Preparing->value,
            TransferStatus::Shipped->value,
        ]);
    }

    /** El folio como lo lee una persona. */
    public function folioNumber(): string
    {
        return sprintf('%s-%d', $this->series, $this->folio);
    }
}

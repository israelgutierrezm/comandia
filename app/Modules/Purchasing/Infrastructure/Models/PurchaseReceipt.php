<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Domain\Enums\PurchaseReceiptStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Una recepción de compra (D26, §3.2).
 *
 * Una recepción **confirmada es inmutable**, por lo mismo que un conteo cerrado (D175) y una orden de producción
 * completada: sus movimientos ya están en el kardex y su costo en un historial que no admite corrección.
 *
 * @property int $id
 * @property PurchaseReceiptStatus $status
 */
final class PurchaseReceipt extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'purchase_receipts';

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'status',
        'folio_branch_id',
        'series',
        'folio',
        'supplier_document_number',
        'received_at',
        'subtotal',
        'tax_total',
        'total',
        'vat_was_creditable',
        'reverses_receipt_id',
        'created_by_membership_id',
        'confirmed_by_membership_id',
        'confirmed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseReceiptStatus::class,
            'received_at' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'vat_was_creditable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // `getRawOriginal` y no `getOriginal`: con el cast de enum, `getOriginal` devuelve el enum construido y
        // compararlo con una cadena nunca sería igual — la guarda rechazaría hasta la propia transición a confirmada.
        // Es la lección que costó una corrida en el paso 5.
        self::updating(function (self $receipt): void {
            $original = $receipt->getRawOriginal('status');

            if ($original !== null && $original !== PurchaseReceiptStatus::Draft->value) {
                throw new \RuntimeException(
                    'Una recepción confirmada o cancelada no se puede modificar. Si se capturó mal, reversa la '
                    .'recepción: sus movimientos ya están en el kardex, que no admite corrección.'
                );
            }
        });
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<PurchaseReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class, 'purchase_receipt_id');
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_receipt_id');
    }

    /**
     * La reversa de ESTA recepción, si alguien la hizo.
     *
     * `HasOne` y no `HasMany` porque el índice único lo garantiza: una recepción se reversa una vez. Que la garantía
     * esté en la base y no sólo en una comprobación es lo que impide que dos peticiones simultáneas dupliquen la
     * salida.
     *
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_receipt_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'confirmed_by_membership_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', PurchaseReceiptStatus::Draft->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isConfirmed(): bool
    {
        return $this->status->isConfirmed();
    }

    /** ¿Es ella misma una reversa de otra? */
    public function isReversal(): bool
    {
        return $this->reverses_receipt_id !== null;
    }

    public function folioNumber(): string
    {
        return sprintf('%s-%d', $this->series, $this->folio);
    }
}

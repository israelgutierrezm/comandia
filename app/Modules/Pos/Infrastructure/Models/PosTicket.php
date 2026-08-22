<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un papel que salió de una impresora sobre una cuenta.
 *
 * @property PosTicketKind $kind
 */
final class PosTicket extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_tickets';

    protected $fillable = [
        'branch_id',
        'kind',
        'pos_account_id',
        'pos_order_id',
        'preparation_area_id',
        'series',
        'folio',
        'issued_by_membership_id',
        'issued_at',
        'reprint_count',

        // Snapshot fiscal congelado al cobrar (D317), sólo en el ticket final si se pidió factura.
        'fiscal_rfc',
        'fiscal_business_name',
        'fiscal_postal_code',
        'fiscal_tax_regime_code',
        'fiscal_cfdi_use_code',
    ];

    protected $attributes = [
        'reprint_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => PosTicketKind::class,
            'issued_at' => 'immutable_datetime',
            'folio' => 'integer',
            'reprint_count' => 'integer',
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
     * @return BelongsTo<PosAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'pos_account_id');
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PreparationArea, $this>
     */
    public function preparationArea(): BelongsTo
    {
        return $this->belongsTo(PreparationArea::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'issued_by_membership_id');
    }

    /**
     * @return HasMany<PosTicketItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosTicketItem::class, 'pos_ticket_id');
    }

    /**
     * El folio como lo lee una persona, o null si este papel no folia.
     */
    public function folioNumber(): ?string
    {
        return $this->series === null ? null : sprintf('%s-%d', $this->series, $this->folio);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCommands(Builder $query): Builder
    {
        return $query->whereIn('kind', [
            PosTicketKind::Command->value,
            PosTicketKind::CommandCancellation->value,
        ]);
    }
}

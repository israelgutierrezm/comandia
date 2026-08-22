<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Pos\Domain\Enums\PosDiscountKind;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un descuento o una cortesía. INMUTABLE.
 *
 * Editar uno ya aplicado cambiaría lo que el corte de anoche explicó. Corregirlo es aplicar otro —o cobrar la
 * diferencia—, nunca reescribir el original.
 *
 * @property PosDiscountKind $kind
 */
final class PosDiscount extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'pos_discounts';

    protected $fillable = [
        'pos_account_id',
        'pos_order_item_id',
        'kind',
        'source',
        'promotion_ulid',
        'value',
        'resulting_amount',
        'reason',
        'applied_by_membership_id',
        'authorized_by_membership_id',
    ];

    /**
     * `created_at` a mano porque el trait apaga los timestamps.
     */
    protected function casts(): array
    {
        return [
            'kind' => PosDiscountKind::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'pos_account_id');
    }

    /**
     * @return BelongsTo<PosOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'applied_by_membership_id');
    }

    /**
     * Quién puso el PIN. Es la firma que el reporte de robo hormiga busca.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /** ¿Es de la cuenta entera? */
    public function isAccountWide(): bool
    {
        return $this->pos_order_item_id === null;
    }

    /**
     * Los de origen PROMOCIÓN. Sirven para saber si una cuenta ya materializó sus promociones (se hace una sola vez, al
     * cobrar) y no volver a aplicarlas en un segundo pago de una división.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFromPromotion(Builder $query): Builder
    {
        return $query->where('source', 'promotion');
    }

    /**
     * Los de la cuenta entera, que son los que el recálculo resta del total.
     *
     * Los de item ya viven en `pos_order_items.discount_amount` y entran por la columna generada `line_total`: sumarlos
     * otra vez aquí los descontaría dos veces.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccountWide(Builder $query): Builder
    {
        return $query->whereNull('pos_order_item_id');
    }
}

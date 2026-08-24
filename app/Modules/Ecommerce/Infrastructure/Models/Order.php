<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Un pedido de la tienda en línea (Iteración 8, Tanda C). Documento foliado por sucursal; totales congelados. Nace
 * `pending_payment` y el pago lo lleva a `paid`.
 */
final class Order extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'orders';

    protected $fillable = [
        'store_id', 'branch_id', 'customer_id',
        'series', 'order_number',
        'delivery_type', 'shipping_zone_id', 'shipping_cost', 'delivery_address',
        'subtotal', 'total', 'status', 'notes',
        'gateway', 'gateway_reference', 'placed_at',
        'accepted_at', 'ready_at', 'completed_at', 'rejected_at', 'rejection_reason', 'accepted_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnlineOrderStatus::class,
            'placed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'order_number' => 'integer',
            // Los importes son DECIMAL en cadena (aritmética exacta), sin cast a float.
        ];
    }

    /**
     * Cambia el estado validando que la transición sea legal (la máquina de estados vive en {@see OnlineOrderStatus}).
     * NO guarda: quien llama fija además los sellos del hito y su actor, y guarda una sola vez. Rechaza saltos ilegales
     * —de `paid` a `completed` sin aceptar— para que ningún endpoint invente caminos.
     */
    public function transitionTo(OnlineOrderStatus $to): void
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new UnprocessableEntityHttpException(
                "Un pedido «{$this->status->label()}» no puede pasar a «{$to->label()}».",
            );
        }

        $this->status = $to;
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'accepted_by_membership_id');
    }

    /** El folio legible: serie + número (p. ej. WEB-000123). */
    public function folio(): string
    {
        return sprintf('%s-%06d', $this->series, $this->order_number);
    }
}

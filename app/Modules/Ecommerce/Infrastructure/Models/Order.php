<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Models;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'immutable_datetime',
            'order_number' => 'integer',
            // Los importes son DECIMAL en cadena (aritmética exacta), sin cast a float.
        ];
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

    /** El folio legible: serie + número (p. ej. WEB-000123). */
    public function folio(): string
    {
        return sprintf('%s-%06d', $this->series, $this->order_number);
    }
}

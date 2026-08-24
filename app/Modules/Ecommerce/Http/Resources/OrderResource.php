<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'folio' => $this->folio(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'delivery_type' => $this->delivery_type,
            'delivery_address' => $this->delivery_address,
            'shipping_cost' => $this->shipping_cost,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            'total' => $this->total,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i): array => [
                'name' => $i->name,
                'unit_price' => $i->unit_price,
                'quantity' => $i->quantity,
                'line_total' => $i->line_total,
            ])->values()),
        ];
    }
}

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
            'status' => $this->status,
            'delivery_type' => $this->delivery_type,
            'delivery_address' => $this->delivery_address,
            'shipping_cost' => $this->shipping_cost,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i): array => [
                'name' => $i->name,
                'unit_price' => $i->unit_price,
                'quantity' => $i->quantity,
                'line_total' => $i->line_total,
            ])->values()),
        ];
    }
}

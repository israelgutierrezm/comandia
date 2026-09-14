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
            // Canal de origen: null en la tienda nativa, el marketplace (didi_food/…) si vino de uno (ADR-015).
            'channel' => $this->channel,
            'delivery_address' => $this->delivery_address,
            'shipping_cost' => $this->shipping_cost,
            // Envío (modo dispatch, ADR-013): la bandeja los muestra y captura al enviar.
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            // El modo de la tienda: la bandeja decide con esto qué acciones ofrecer (preparar/listo vs
            // empacar/enviar/entregar). Una tienda por tenant, así que cargarla no es costoso.
            'fulfillment_mode' => $this->whenLoaded('store', fn () => $this->store?->fulfillment_mode?->value),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            'total' => $this->total,
            'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'packed_at' => $this->packed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
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

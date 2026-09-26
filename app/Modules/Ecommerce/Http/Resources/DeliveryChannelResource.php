<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeliveryChannelSetting
 */
final class DeliveryChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'channel' => $this->channel->value,
            'channel_label' => $this->channel->label(),
            'is_active' => $this->is_active,
            'external_store_id' => $this->external_store_id,
            'commission_rate' => $this->commission_rate,
            'branch_ulid' => $this->whenLoaded('branch', fn () => $this->branch?->ulid),
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            // Los secretos NUNCA salen; sólo si ya están guardados (para pintar el estado en la UI y decir «vacío =
            // conservar» en el formulario).
            'has_webhook_secret' => filled($this->getAttribute('webhook_secret')),
            'has_api_key' => filled($this->getAttribute('api_key')),
            'has_api_secret' => filled($this->getAttribute('api_secret')),
        ];
    }
}

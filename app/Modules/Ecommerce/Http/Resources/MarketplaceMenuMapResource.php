<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketplaceMenuMap
 */
final class MarketplaceMenuMapResource extends JsonResource
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
            'external_item_id' => $this->external_item_id,
            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article?->ulid,
                'name' => $this->article?->displayName(),
            ]),
        ];
    }
}

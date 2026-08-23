<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleStoreSetting
 */
final class ArticleStoreSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'stock_policy' => $this->stock_policy,
            'is_in_store' => $this->is_in_store,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'channel_price' => $this->channel_price,
        ];
    }
}

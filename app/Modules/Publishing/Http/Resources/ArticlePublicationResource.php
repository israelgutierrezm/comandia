<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Http\Resources;

use App\Modules\Publishing\Infrastructure\Models\ArticlePublication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticlePublication
 */
final class ArticlePublicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'long_description' => $this->long_description,
            'sort_order' => $this->sort_order,
            'is_visible' => $this->is_visible,
            'images' => $this->images->map(fn ($image): array => [
                'ulid' => $image->ulid,
                'url' => $image->url(),
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
            ])->values(),
        ];
    }
}

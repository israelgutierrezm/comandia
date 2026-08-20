<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosAreaRoute
 */
final class PosAreaRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            // Qué clase de regla es, resuelto en el servidor: la pantalla ordena las de artículo después de las de
            // categoría porque son las que ganan, y no debería deducirlo de qué llave viene en null.
            'is_article_override' => $this->isArticleOverride(),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'article' => $this->whenLoaded('article', fn () => $this->article === null ? null : [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
            ]),

            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'ulid' => $this->category->ulid,
                'name' => $this->category->name,
            ]),

            'preparation_area' => $this->whenLoaded('preparationArea', fn () => [
                'ulid' => $this->preparationArea->ulid,
                'name' => $this->preparationArea->name,
                'code' => $this->preparationArea->code,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleCategory
 */
final class ArticleCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'level' => $this->level,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,

            // El padre por su ULID, nunca por su PK. Sólo cuando la relación está cargada: la
            // alternativa —cargarla siempre— produciría una consulta por fila del listado.
            'parent_ulid' => $this->whenLoaded('parent', fn () => $this->parent?->ulid),

            // El cliente necesita saber si puede colgarle una subcategoría sin tener que conocer la
            // regla de D18. Que la regla viva en el servidor y viaje como dato es lo que impide que
            // el frontend acabe con su propia copia de la regla, desactualizada.
            'can_have_children' => $this->canBeParent(),

            'children' => ArticleCategoryResource::collection($this->whenLoaded('children')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

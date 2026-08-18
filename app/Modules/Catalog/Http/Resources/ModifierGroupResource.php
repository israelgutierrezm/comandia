<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModifierGroup
 */
final class ModifierGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,

            // Las reglas de selección (D7). Viajan siempre porque el POS las necesita para validar antes de
            // comandar, y una copia de la regla en el cliente sería una copia que se desactualiza.
            'is_required' => $this->is_required,
            'min_selections' => $this->min_selections,

            // `null` = sin límite. Explícito para que el cliente no tenga que interpretar la ausencia.
            'max_selections' => $this->max_selections,
            'has_selection_limit' => $this->hasSelectionLimit(),

            'allows_quantity' => $this->allows_quantity,

            'status' => $this->status->value,

            // El orden dentro del artículo, sólo cuando el grupo se lee A TRAVÉS de un artículo: vive en el
            // pivote porque el mismo grupo puede ir primero en un artículo y tercero en otro.
            'sort_order' => $this->whenPivotLoaded('article_modifier_group', fn () => $this->pivot->sort_order),

            'modifiers' => $this->whenLoaded(
                'modifiers',
                fn () => $this->modifiers->map(fn (Modifier $modifier): array => [
                    'ulid' => $modifier->ulid,
                    'name' => $modifier->name,
                    'extra_price' => $modifier->extra_price,
                    'is_paid' => $modifier->isPaid(),
                    'sort_order' => $modifier->sort_order,
                    'status' => $modifier->status->value,
                ])->values(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

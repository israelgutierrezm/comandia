<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Resources;

use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Costing\Infrastructure\Models\RecipeLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recipe
 *
 * La receta viaja **completa**, con sus líneas dentro: es como se guarda y es una unidad de sentido. Las
 * líneas no tienen ULID porque no son recursos propios.
 *
 * No trae costos. El desglose del costeo es el paso 6 y tendrá su propio endpoint
 * (`GET /articles/{ulid}/cost-breakdown`), porque un costo sin desglose es un número que nadie cree.
 */
final class RecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            'article_ulid' => $this->whenLoaded('article', fn () => $this->article?->ulid),

            // Como cadena: es el divisor del costo total de la receta (§7, P3).
            'output_quantity' => $this->output_quantity,

            'output_unit' => $this->whenLoaded('outputUnit', fn () => $this->outputUnit === null ? null : [
                'ulid' => $this->outputUnit->ulid,
                'code' => $this->outputUnit->code,
                'name' => $this->outputUnit->name,
            ]),

            'notes' => $this->notes,
            'status' => $this->status->value,

            'lines' => $this->whenLoaded(
                'lines',
                fn () => $this->lines->map(fn (RecipeLine $line): array => [
                    'component' => [
                        'ulid' => $line->component?->ulid,
                        'name' => $line->component?->name,
                        'base_unit_code' => $line->component?->baseUnit?->code,
                    ],
                    'quantity' => $line->quantity,
                    'unit' => [
                        'ulid' => $line->unit?->ulid,
                        'code' => $line->unit?->code,
                    ],

                    // D21. Viaja explícito aunque sea 100 en casi todas las líneas, porque es el número
                    // que explica por qué el costo de la línea no es cantidad × costo a secas.
                    'yield_percent' => $line->yield_percent,

                    'sort_order' => $line->sort_order,
                ])->values(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\ProductionOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionOrderLine
 *
 * Un insumo consumido, con el snapshot de lo que la receta pedía.
 *
 * Viajan las dos cosas —lo que se consumió y lo que la receta decía— porque contestan preguntas distintas: la primera
 * dice cuánto salió del almacén, y la segunda **por qué esa cantidad**. Con sólo la primera, un consumo raro no se
 * puede distinguir de una receta mal capturada o cambiada después.
 */
final class ProductionOrderLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'component' => [
                'ulid' => $this->component->ulid,
                'name' => $this->component->name,
                'base_unit_code' => $this->component->baseUnit?->code,
            ],

            'lot' => $this->lot === null ? null : [
                'ulid' => $this->lot->ulid,
                'code' => $this->lot->code,
                'expires_at' => $this->lot->expires_at?->toDateString(),
            ],

            // Lo que salió, en la unidad base del componente.
            'consumed_quantity' => $this->consumed_quantity,

            // El snapshot de la línea de receta: la cantidad como estaba escrita, su unidad y el rendimiento.
            'recipe' => [
                'quantity' => $this->recipe_quantity,
                'unit_code' => $this->recipeUnit?->code,
                'yield_percent' => $this->yield_percent,
            ],

            'unit_cost_at_production' => $this->unit_cost_at_production,
            'line_cost' => $this->lineCost(),

            // El renglón del kardex que este consumo produjo: hace navegable orden → kardex.
            'movement_ulid' => $this->whenLoaded('movement', fn () => $this->movement?->ulid),
        ];
    }
}

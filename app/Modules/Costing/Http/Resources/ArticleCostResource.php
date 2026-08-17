<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Resources;

use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Identity\Application\MembershipNameResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleCost
 */
final class ArticleCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            // Como cadena: DECIMAL(12,4) y entra en aritmética de costeo. Convertirlo a float aquí
            // desharía la razón por la que la columna tiene cuatro decimales.
            'unit_cost' => $this->unit_cost,

            // Identificador estable para el código, etiqueta en español para el humano (D87).
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),

            // Si cuenta como adquisición en el sentido de D14. El cliente lo necesita para el
            // promedio del periodo, que se calcula SÓLO sobre adquisiciones: mezclarlo con costos
            // calculados daría un número sin significado.
            'is_acquisition' => $this->origin->isAcquisition(),

            'notes' => $this->notes,

            // La cadena causal: "la torta subió porque subió el jitomate".
            'source_cost_ulid' => $this->whenLoaded('sourceCost', fn () => $this->sourceCost?->ulid),

            // NULL = lo calculó un job y no una persona. No se inventa un actor: uno falso en un
            // registro de evidencia es indistinguible de uno real.
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'ulid' => $this->actor->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->actor)->short(),
                'employee_code' => $this->actor->employee_code,
            ]),

            'effective_at' => $this->effective_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

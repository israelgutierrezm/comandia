<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Identity\Application\MembershipNameResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceChange
 */
final class PriceChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            // `null` = primera fijación. Es distinto de un cambio desde cero: "no tenía precio" y "valía $0"
            // son dos cosas, y la segunda sería una cortesía.
            'previous_price' => $this->previous_price,
            'new_price' => $this->new_price,

            // El estado del costeo en ese momento. Es lo que contesta la pregunta que el historial existe
            // para responder: ¿subió porque subió el costo, o porque alguien quiso?
            'suggested_price' => $this->suggested_price,
            'unit_cost_at_change' => $this->unit_cost_at_change,

            // MARKUP (utilidad ÷ costo) es lo que se guardó; el MARGEN (utilidad ÷ precio) se calcula al
            // leer. Guardar los dos invitaría a que se contradijeran (D13, §7).
            'markup_percent' => $this->markup_percent,
            'margin_percent' => $this->marginPercent(),

            'reason' => $this->reason,

            // NULL = cambió el precio maestro; con valor = el override de esa sucursal (paso 9).
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'ulid' => $this->actor->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->actor)->short(),
                'employee_code' => $this->actor->employee_code,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

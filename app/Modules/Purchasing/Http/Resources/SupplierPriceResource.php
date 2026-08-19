<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplierPrice
 *
 * Una observación de precio.
 *
 * `unit_price` es **siempre por unidad base**: es lo que hace comparables dos proveedores que venden en presentaciones
 * distintas. Lo capturado viaja aparte, porque es lo primero que alguien pide cuando la comparación no le cuadra.
 */
final class SupplierPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            'unit_price' => $this->unit_price,
            'currency' => $this->currency,

            'observed_at' => $this->observed_at?->toDateString(),

            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'is_confirmed_purchase' => $this->source->isConfirmedPurchase(),

            'supplier' => $this->whenLoaded('supplier', fn () => [
                'ulid' => $this->supplier->ulid,
                'code' => $this->supplier->code,
                'name' => $this->supplier->displayName(),
            ]),

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'base_unit_code' => $this->article->baseUnit?->code,
            ]),

            // La presentación en la que se observó, si fue en una. Es el contexto que explica el precio normalizado.
            'presentation' => $this->whenLoaded('presentation', fn () => $this->presentation === null ? null : [
                'ulid' => $this->presentation->ulid,
                'name' => $this->presentation->name,
                'quantity_in_base_unit' => $this->presentation->quantity_in_base_unit,
            ]),

            'observed_quantity' => $this->observed_quantity,
            'observed_price' => $this->observed_price,

            // NULL = lo escribió el sistema al confirmar una recepción. No se inventa un actor.
            'registered_by' => $this->whenLoaded('registeredBy', fn () => $this->registeredBy === null ? null : [
                'ulid' => $this->registeredBy->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->registeredBy)->short(),
                'employee_code' => $this->registeredBy->employee_code,
            ]),

            'notes' => $this->notes,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

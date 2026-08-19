<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 *
 * Un renglón del kardex. Se lee como un estado de cuenta: qué pasó, cuánto, y con qué saldo quedó.
 */
final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,

            // Identificador estable para el código, etiqueta en español para el humano (D87).
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),

            // Como CADENA y siempre positiva: es un DECIMAL(12,4) y entra en aritmética de costeo. La
            // dirección viaja aparte a propósito, para que ninguna suma pueda ignorar el signo por descuido.
            'quantity' => $this->quantity,

            // La misma cantidad con el signo ya aplicado, para quien sólo quiera sumar. Se calcula aquí y no
            // en el cliente porque es la operación en la que se cuela el error.
            'signed_quantity' => $this->signedQuantity(),

            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,

            // El saldo DESPUÉS de este movimiento, congelado. Es lo que hace que el kardex se lea como un
            // estado de cuenta sin acumular nada en el cliente.
            'balance_after' => $this->balance_after,

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'base_unit_code' => $this->article->baseUnit?->code,
            ]),

            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),

            // `null` = el artículo no lleva lotes, que es el caso de la mayoría.
            'lot' => $this->whenLoaded('lot', fn () => $this->lot === null ? null : [
                'ulid' => $this->lot->ulid,
                'code' => $this->lot->code,
                'expires_at' => $this->lot->expires_at?->toDateString(),
            ]),

            // El motivo, sólo en las mermas (D27). `requires_evidence` viaja para que la UI pueda advertir que ese
            // motivo exige foto, aunque la subida llegue en la Iteración 11 (P5).
            'waste_reason' => $this->whenLoaded('wasteReason', fn () => $this->wasteReason === null ? null : [
                'ulid' => $this->wasteReason->ulid,
                'name' => $this->wasteReason->name,
                'requires_evidence' => $this->wasteReason->requires_evidence,
            ]),

            // El documento que lo causó, por su tipo y su ULID PÚBLICO. Nunca la llave interna (D91, D151):
            // el ULID está congelado en el asiento, así que sigue siendo legible aunque el documento se vaya.
            'source' => $this->source_type === null ? null : [
                'type' => class_basename($this->source_type),
                'ulid' => $this->source_ulid,
            ],

            // NULL = lo movió un job y no una persona. No se inventa un actor.
            'actor' => $this->whenLoaded('actor', fn () => $this->actor === null ? null : [
                'ulid' => $this->actor->ulid,
                'name' => app(MembershipNameResolver::class)->resolve($this->actor)->short(),
                'employee_code' => $this->actor->employee_code,
            ]),

            'notes' => $this->notes,

            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

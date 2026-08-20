<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerCreditMovement
 *
 * El estado de cuenta de un cliente, que es lo que se le muestra con él delante.
 */
final class CustomerCreditMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            // CON signo: un cargo suma a lo que debe, un abono resta. Publicarlo en valor absoluto obligaría a la
            // pantalla a deducir el sentido del tipo, y ahí es donde un abono acaba pintado como una deuda.
            'amount' => $this->amount,

            // El saldo que quedó después de este movimiento. Es lo que permite contestar «¿cuánto debía el 3 de marzo?»
            // sin sumar la historia entera.
            'balance_after' => $this->balance_after,

            'source_type' => $this->source_type,
            'source_ulid' => $this->source_ulid,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

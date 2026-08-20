<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Infrastructure\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'status' => $this->status,

            'credit' => $this->whenLoaded('credit', fn () => $this->credit === null ? null : [
                'limit' => $this->credit->credit_limit,
                'balance' => $this->credit->balance,

                // Cuánto puede fiar todavía, resuelto en el servidor: es una resta de dinero y hacerla en el cliente es
                // donde se cuelan los errores de redondeo (D134). Nunca negativo — «menos doscientos disponibles» no
                // significa nada para quien lo lee en el mostrador.
                'available' => $this->credit->available(),

                'is_enabled' => $this->credit->is_enabled,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 *
 * Un proveedor.
 *
 * `display_name` lo calcula el servidor y no el cliente, por la lección de D139: «el nombre comercial si lo tiene, la
 * razón social si no» es una regla, y una regla duplicada en cada pantalla se aplica distinto en alguna.
 */
final class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,

            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'display_name' => $this->displayName(),

            'rfc' => $this->rfc,

            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,

            // `null` es «no se sabe» y cero es «de contado». La UI tiene que distinguirlos, así que no se rellena el
            // nulo con un cero.
            'payment_terms_days' => $this->payment_terms_days,

            'notes' => $this->notes,

            'status' => $this->status->value,
            'is_active' => $this->isActive(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

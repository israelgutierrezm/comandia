<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
final class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Nunca la PK secuencial (ARQUITECTURA_MAESTRA §7).
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,

            // Viaja al cliente porque los totales "del día" se presentan en la hora de la
            // sucursal, no del navegador (§7).
            'timezone' => $this->timezone,

            'default_warehouse' => $this->whenLoaded(
                'defaultWarehouse',
                fn () => [
                    'ulid' => $this->defaultWarehouse?->ulid,
                    'name' => $this->defaultWarehouse?->name,
                ],
            ),

            'address' => [
                'street' => $this->street,
                'exterior_number' => $this->exterior_number,
                'interior_number' => $this->interior_number,
                'neighborhood' => $this->neighborhood,
                'municipality' => $this->municipality,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],

            'phone' => $this->phone,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

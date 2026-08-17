<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Warehouse
 */
final class WarehouseResource extends JsonResource
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
            'status' => $this->status->value,

            // `kind` se expone además de `branch`: para el cliente, "central" es una propiedad
            // del almacén y no algo que deba deducir de la ausencia de sucursal (D11).
            'kind' => $this->kind->value,
            'is_central' => $this->isCentral(),

            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

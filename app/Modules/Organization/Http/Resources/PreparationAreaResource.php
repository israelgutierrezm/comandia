<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PreparationArea
 */
final class PreparationAreaResource extends JsonResource
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
            'sort_order' => $this->sort_order,

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            // El almacén del que descuenta esta área: es la mitad de su razón de ser
            // (destino de comandas Y punto de consumo de inventario, §3), así que no es un
            // detalle opcional de la representación.
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'name' => $this->warehouse->name,
                'is_central' => $this->warehouse->isCentral(),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

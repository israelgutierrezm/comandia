<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
final class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Nunca la PK secuencial (§7).
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,

            // El identificador de la magnitud es el valor por el que el cliente compara y filtra;
            // la etiqueta es lo que se pinta. Los dos, por lo mismo que en D87: el texto es
            // traducible y no puede ser llave de nada.
            'dimension' => $this->dimension->value,
            'dimension_label' => $this->dimension->label(),

            // Viaja como cadena y no como número: es un DECIMAL(18,8) y convertirlo a float en el
            // JSON perdería precisión justo en el factor que multiplica todas las cantidades.
            'factor_to_base' => $this->factor_to_base,
            'is_system_base' => $this->isSystemBase(),

            'status' => $this->status->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

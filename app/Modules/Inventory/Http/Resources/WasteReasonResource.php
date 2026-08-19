<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WasteReason
 *
 * Un motivo del catálogo de mermas del negocio (D27).
 */
final class WasteReasonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,

            // Política del negocio, declarada hoy aunque la subida de archivos llegue en la Iteración 11 (P5).
            // Viaja para que la UI pueda advertir «este motivo exige foto» antes de que exista el almacenamiento.
            'requires_evidence' => $this->requires_evidence,

            'status' => $this->status->value,
            'is_active' => $this->isActive(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Terminal
 */
final class TerminalResource extends JsonResource
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

            // Lo primero que se pregunta cuando una sucursal reporta un problema: el POS se
            // detiene sin internet (riesgo aceptado, §6.9) y saber cuándo se vio por última
            // vez la terminal distingue "se cayó la red" de "está apagada".
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),


            // La impresora asignada. `null` significa «no imprime», que es distinto de «no se sabe»: la interfaz lo
            // dice con palabras en lugar de dejar el renglón vacío.
            'printer' => $this->whenLoaded('printer', fn () => $this->printer === null ? null : [
                'ulid' => $this->printer->ulid,
                'code' => $this->printer->code,
                'name' => $this->printer->name,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

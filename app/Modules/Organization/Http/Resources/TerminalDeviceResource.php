<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un dispositivo enrolado a una terminal compartida (ADR-012).
 *
 * NUNCA expone el secreto: `secret_hash` está oculto en el modelo y el secreto en claro sólo se entrega
 * una vez, aparte de este Resource, en la respuesta del enrolamiento.
 *
 * @mixin TerminalDevice
 */
final class TerminalDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'label' => $this->label,

            // Como en la terminal: distingue «se cayó la red» de «está apagado» cuando una caja reporta
            // problemas. `null` = nunca ha canjeado sesión desde que se enroló.
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // La revocación es por fila (aparato perdido) sin dar de baja la terminal. `null` = vigente.
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            'terminal' => $this->whenLoaded('terminal', fn (): array => [
                'ulid' => $this->terminal->ulid,
                'name' => $this->terminal->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

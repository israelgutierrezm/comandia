<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Resources;

use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PrintAgent
 *
 * El token NO sale nunca por aquí: se muestra una sola vez, en la respuesta del alta o de la rotación. Publicarlo en la
 * lista lo dejaría en cualquier caché del navegador y en cualquier registro de red.
 */
final class PrintAgentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'status' => $this->status,

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            // La pregunta de una cocina no es «¿falló el trabajo?», es «¿está vivo el agente?». Sin esto, un agente
            // apagado y uno sin trabajos se ven idénticos.
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'is_alive' => $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2)),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

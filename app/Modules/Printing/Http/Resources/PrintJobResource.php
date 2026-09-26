<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Resources;

use App\Modules\Printing\Domain\Enums\PrintJobStatus;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin PrintJob
 */
final class PrintJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'allowed_next' => array_map(
                fn (PrintJobStatus $s): string => $s->value,
                $this->status->allowedNext(),
            ),

            'printer' => $this->whenLoaded('printer', fn () => [
                'ulid' => $this->printer->ulid,
                'name' => $this->printer->name,
                'connection' => $this->printer->connection->value,

                // El destino y el ancho van con el trabajo porque son lo que el agente necesita para imprimirlo: sin
                // ellos tendría que consultar la impresora por su cuenta, con un endpoint más y una llamada más por
                // trabajo.
                'target' => $this->printer->target,
                'paper_width' => $this->printer->paper_width,
                'supports_cash_drawer' => $this->printer->supports_cash_drawer,
            ]),

            // Lo que hay que imprimir, congelado al encolar. Es la excepción de JSON autorizada por CLAUDE.md. Sin el id
            // interno de quien autorizó, que las aperturas de cajón anteriores guardaban: nunca se exponen ids
            // secuenciales (las nuevas llevan su ULID).
            'payload' => is_array($this->payload) ? Arr::except($this->payload, ['actor_membership_id']) : $this->payload,

            'attempts' => $this->attempts,
            'claimed_by_agent' => $this->claimed_by_agent,
            'last_error' => $this->last_error,

            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // La sucursal del trabajo, con su zona horaria: la pantalla pinta las horas en la de la sucursal y no tiene
            // por qué deducirla de la impresora (que un cajero ni siquiera puede listar).
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
                'timezone' => $this->branch->timezone,
            ]),

            'ticket' => $this->whenLoaded('ticket', fn () => $this->ticket === null ? null : [
                'ulid' => $this->ticket->ulid,
                'kind' => $this->ticket->kind->value,
            ]),
        ];
    }
}

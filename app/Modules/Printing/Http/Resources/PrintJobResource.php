<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Resources;

use App\Modules\Printing\Domain\Enums\PrintJobStatus;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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

            // Lo que hay que imprimir, congelado al encolar. Es la excepción de JSON autorizada por CLAUDE.md.
            'payload' => $this->payload,

            'attempts' => $this->attempts,
            'claimed_by_agent' => $this->claimed_by_agent,
            'last_error' => $this->last_error,

            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'printed_at' => $this->printed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'ticket' => $this->whenLoaded('ticket', fn () => $this->ticket === null ? null : [
                'ulid' => $this->ticket->ulid,
                'kind' => $this->ticket->kind->value,
            ]),
        ];
    }
}

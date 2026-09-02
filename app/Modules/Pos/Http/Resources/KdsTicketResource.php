<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItemModifier;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una comanda en el tablero de cocina: el encabezado (mesa, folio, desde cuándo) y sus platillos vivos.
 *
 * @mixin PosTicket
 */
final class KdsTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'series' => $this->series,
            'folio' => $this->folio,

            // Una comanda reimpresa es comida que puede prepararse dos veces si nadie se da cuenta: el tablero lo avisa.
            'reprint_count' => $this->reprint_count,

            // El reloj del tablero: desde cuándo espera esta comanda. UTC; la pantalla lo presenta en la zona de la sucursal.
            'issued_at' => $this->issued_at?->toIso8601String(),

            'account' => $this->account === null ? null : [
                'ulid' => $this->account->ulid,
                'display_name' => $this->account->displayName(),
                'folio' => $this->account->folioNumber(),
            ],

            // Sólo las líneas vivas de esta área (las precargó el controlador con ese filtro).
            'items' => $this->order?->items->map(fn (PosOrderItem $i): array => [
                'ulid' => $i->ulid,
                'article_name' => $i->article_name,
                'note' => $i->note,
                'quantity' => $i->quantity,
                'status' => $i->status->value,
                'status_label' => $i->status->label(),
                'modifiers' => $i->modifiers->map(fn (PosOrderItemModifier $m): string => (string) $m->modifier_name)->all(),
            ])->all() ?? [],
        ];
    }
}

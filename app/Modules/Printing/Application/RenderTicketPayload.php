<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application;

use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Pos\Infrastructure\Models\PosTicketItem;

/**
 * Convierte un ticket del POS en lo que el agente tiene que imprimir.
 *
 * ## Por qué el payload es DATOS y no texto ya formateado
 *
 * Sería más fácil devolver la cadena con los espacios puestos, y ataría el papel al ancho: una comanda armada para 80 mm
 * sale ilegible en una impresora de 58, y el ancho es de la impresora, no del documento. Con datos, el agente pagina
 * según la impresora que tiene enfrente.
 *
 * Y hay una segunda razón, más importante: el puente Flutter y el agente de Windows son dos implementaciones (§9.4). Si
 * el servidor mandara texto ESC/POS, cada una tendría que deshacerlo para adaptarlo. Con datos, las dos rinden lo mismo
 * a su manera.
 *
 * ## Todo lo que va aquí está CONGELADO
 *
 * Los nombres salen de la línea del ticket, no del catálogo de hoy: `article_name` se copió al capturar (D28). Así que
 * este payload dice lo que decía el documento cuando se emitió, y reimprimir saca el mismo papel un mes después.
 */
final readonly class RenderTicketPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forTicket(PosTicket $ticket): array
    {
        $ticket->loadMissing(['account.restaurantTable', 'order', 'preparationArea', 'items.item', 'branch']);

        return [
            // La versión del contrato. Existe desde el primer trabajo porque el agente vive en máquinas que se
            // actualizan tarde: cuando el payload cambie, un agente viejo tiene que poder decir «no entiendo esto» en
            // lugar de imprimir basura.
            'version' => 1,

            'kind' => $ticket->kind->value,
            'kind_label' => $ticket->kind->label(),

            'business' => [
                'name' => $ticket->branch?->name,
            ],

            'account' => [
                'folio' => $ticket->account?->folioNumber(),
                'display_name' => $ticket->account?->displayName(),
            ],

            'order_sequence' => $ticket->order?->sequence,

            'area' => $ticket->preparationArea?->name,

            'folio' => $ticket->folioNumber(),
            'issued_at' => $ticket->issued_at?->toIso8601String(),

            'items' => $ticket->items->map(fn (PosTicketItem $renglon): array => [
                'quantity' => $renglon->quantity,
                'name' => $renglon->item?->article_name,

                // Los modificadores van con la línea porque en una comanda son la mitad del mensaje: «sin cebolla» es lo
                // que la cocina necesita leer, y separarlo del plato lo vuelve inútil.
                'modifiers' => $renglon->item?->modifiers->map(fn ($m): array => [
                    'name' => $m->modifier_name,
                    'quantity' => $m->quantity,
                ])->all() ?? [],
            ])->all(),
        ];
    }

    /**
     * El payload de una apertura de cajón.
     *
     * No lleva contenido porque no imprime nada: es la secuencia que abre el cajón. Lleva quién y por qué **para el
     * agente**, aunque nunca salgan en papel, porque un agente que registra lo que hizo es lo único que permite
     * reconstruir una apertura desde el otro lado cuando la de este lado no cuadra.
     *
     * @return array<string, mixed>
     */
    public function forDrawer(string $reason, int $actorMembershipId): array
    {
        return [
            'version' => 1,
            'kind' => 'drawer_open',
            'reason' => $reason,
            'actor_membership_id' => $actorMembershipId,
        ];
    }
}

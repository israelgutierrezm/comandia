<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application;

use App\Modules\Pos\Domain\Enums\PosTicketKind;
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
        $ticket->loadMissing([
            'account.restaurantTable',
            'order',
            'preparationArea',
            'items.item',
            'branch',
        ]);

        // El ticket FINAL desglosa los pagos; una comanda no. Cargarlos siempre sería una consulta de más en la
        // operación más frecuente del turno.
        if ($ticket->kind === PosTicketKind::FinalReceipt) {
            $ticket->loadMissing(['account.payments.method', 'account.items']);
        }

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

            // El desglose de dinero, sólo en el ticket final. Los precios son IVA incluido (D30), así que el impuesto
            // va desglosado y NO sumado — un ticket que lo sumara cobraría dos veces sobre el papel.
            'totals' => $ticket->kind === PosTicketKind::FinalReceipt ? [
                'subtotal' => $ticket->account?->subtotal,
                'discount_total' => $ticket->account?->discount_total,
                'vat_total' => $ticket->account?->vat_total,
                'total' => $ticket->account?->total,
                'tip_total' => $ticket->account?->tip_total,
                'change_total' => $ticket->account?->change_total,
            ] : null,

            'payments' => $ticket->kind === PosTicketKind::FinalReceipt
                ? ($ticket->account?->payments->map(fn ($p): array => [
                    'method' => $p->method?->name,
                    'amount' => $p->amount,
                    'tip_amount' => $p->tip_amount,
                    'reference' => $p->reference,
                ])->all() ?? [])
                : [],

            // Un ticket final lista lo que se COBRÓ —las líneas de la cuenta—; una comanda lista lo que salió en ese
            // papel. Son dos preguntas distintas y por eso la fuente es distinta.
            'items' => ($ticket->kind === PosTicketKind::FinalReceipt
                ? $ticket->account?->items->map(fn ($item): array => [
                    'quantity' => $item->quantity,
                    'name' => $item->article_name,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'modifiers' => [],
                ])->all() ?? []
                : null) ?? $ticket->items->map(fn (PosTicketItem $renglon): array => [
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
     * El payload de una comanda de un pedido de la tienda en línea (Iteración 8, Tanda D parte 2).
     *
     * Produce **el mismo contrato** que una comanda del POS —`kind = 'command'`, líneas con nombre y cantidad congelados—
     * para que el agente y la pantalla la rindan idénticas, vengan del mostrador o de la tienda. Sin `PosTicket`: la tienda
     * no tiene uno, así que se arma con los primitivos del evento. Sin dinero, como toda comanda. Sin modificadores en v1.
     *
     * @param  list<array{name: string, quantity: string}>  $items
     * @return array<string, mixed>
     */
    public function forEcommerceComanda(?string $businessName, string $orderFolio, ?string $areaName, array $items, string $issuedAt): array
    {
        return [
            'version' => 1,
            'kind' => PosTicketKind::Command->value,
            'kind_label' => PosTicketKind::Command->label(),

            'business' => ['name' => $businessName],

            // La identidad del pedido para la cocina: su folio y que es de la tienda en línea.
            'account' => ['folio' => $orderFolio, 'display_name' => 'Pedido en línea'],

            'order_sequence' => null,
            'area' => $areaName,
            'folio' => null, // la comanda no se folía aparte: el folio del pedido la identifica
            'issued_at' => $issuedAt,

            'totals' => null,
            'payments' => [],

            'items' => array_map(fn (array $i): array => [
                'quantity' => $i['quantity'],
                'name' => $i['name'],
                'modifiers' => [],
            ], $items),
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

<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application;

use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Printing\Domain\Enums\PrintJobKind;
use App\Modules\Printing\Domain\Exceptions\PrintJobException;
use App\Modules\Printing\Infrastructure\Models\PrintJob;

/**
 * Encola lo que hay que imprimir.
 *
 * ## A qué impresora va cada cosa
 *
 * Una **comanda** va a la impresora de su área de preparación; un **ticket** de la cuenta, a la de la terminal donde se
 * cobra. Es el ruteo que el paso 2 dejó puesto con `preparation_areas.printer_id` y `terminals.printer_id`.
 *
 * ## Un área sin impresora NO revienta la operación
 *
 * Si el área no tiene impresora asignada, no se encola nada y se sigue adelante. Es deliberado y va contra el instinto:
 * lo natural sería fallar y avisar. Y fallar aquí **tumbaría el comandar**, es decir, impediría vender porque falta una
 * configuración de impresoras — exactamente lo que §6.2 prohíbe cuando dice que el POS nunca se bloquea.
 *
 * La contrapartida honesta: una cocina puede quedarse sin recibir papeles y nadie se entera hasta que alguien pregunta.
 * Lo cubre la pantalla de trabajos —que muestra qué se encoló y qué no— y lo cubrirá la alerta de agente no visto. No
 * lo cubre un error en el momento de vender, y esa elección es a propósito.
 */
final readonly class QueuePrintJob
{
    public function __construct(private RenderTicketPayload $payloads) {}

    /**
     * Encola un ticket para su impresora.
     *
     * @return PrintJob|null `null` si no hay a dónde mandarlo
     */
    public function forTicket(PosTicket $ticket): ?PrintJob
    {
        $printerId = $this->printerFor($ticket);

        if ($printerId === null) {
            return null;
        }

        return PrintJob::create([
            'branch_id' => $ticket->branch_id,
            'kind' => PrintJobKind::Ticket,
            'pos_ticket_id' => $ticket->id,
            'printer_id' => $printerId,

            // El payload se arma AQUÍ y se guarda: reimprimir vuelve a mandar el mismo papel, aunque el ticket cambie.
            'payload' => $this->payloads->forTicket($ticket),
        ])->refresh();
    }

    /**
     * Encola la apertura del cajón.
     *
     * Exige una impresora con cajón. Sin ella no hay nada que hacer y sí hay que decirlo: a diferencia de una comanda
     * sin impresora, aquí el usuario está pidiendo explícitamente que se abra el cajón y espera que se abra. Un silencio
     * lo dejaría picando el botón.
     */
    public function forDrawer(Printer $printer, string $reason, int $actorMembershipId): PrintJob
    {
        if (! $printer->supports_cash_drawer) {
            throw PrintJobException::printerWithoutDrawer((string) $printer->name);
        }

        return PrintJob::create([
            'branch_id' => $printer->branch_id,
            'kind' => PrintJobKind::DrawerOpen,
            'printer_id' => $printer->id,
            'payload' => $this->payloads->forDrawer($reason, $actorMembershipId),
        ])->refresh();
    }

    /**
     * La impresora que le toca a este papel.
     *
     * La comanda, a la de su área. El resto —ticket de cierre, ticket final—, a la de la terminal que cobra… que en esta
     * iteración todavía no se conoce en el momento de emitir, así que cae a la primera impresora con cajón de la
     * sucursal. Es una simplificación declarada: la terminal entra en el paso 10, con el cobro, y ahí se sustituye por
     * `terminals.printer_id`. Mientras tanto, el ticket sale por la impresora de la caja, que es donde tiene que salir
     * en un negocio de una sola caja.
     */
    private function printerFor(PosTicket $ticket): ?int
    {
        if ($ticket->kind->goesToArea()) {
            $ticket->loadMissing('preparationArea');

            return $ticket->preparationArea?->printer_id === null
                ? null
                : (int) $ticket->preparationArea->printer_id;
        }

        $caja = Printer::query()
            ->where('branch_id', $ticket->branch_id)
            ->where('supports_cash_drawer', true)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        return $caja?->id;
    }
}

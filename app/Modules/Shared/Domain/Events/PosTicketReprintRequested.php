<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se pidió reimprimir un ticket que NO va a un área de preparación (el recibo final, la precuenta).
 *
 * ## Por qué un evento aparte del de comandar
 *
 * Reimprimir una comanda ya tenía camino: `PosOrderCommanded`, que además la vuelve a mostrar en el tablero de cocina.
 * Un recibo final no va a ninguna cocina ni al KDS —es el comprobante del cliente—, así que reusar aquel evento habría
 * re-anunciado en el KDS un papel que nadie prepara. Este evento sólo dice «vuelve a sacar ESTE ticket por su
 * impresora», y `Printing` lo encola con el mismo `QueuePrintJob::forTicket` que usa al pagar.
 *
 * Vive en el kernel por lo mismo que los demás hechos del POS (D231): `Printing` reacciona sin conocer a `Pos`, y `Pos`
 * no conoce a `Printing`. Lleva el `ticketId` interno —no el ULID— porque viaja dentro de la aplicación hacia una tabla
 * del propio dominio, igual que `PosOrderCommanded`.
 */
final readonly class PosTicketReprintRequested implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $ticketId,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

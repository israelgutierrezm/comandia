<?php

declare(strict_types=1);

namespace App\Modules\Printing\Listeners;

use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Printing\Application\QueuePrintJob;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Shared\Domain\Events\PosTicketReprintRequested;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lo que se comanda o se cancela, se imprime.
 *
 * ## NINGÚN fallo de aquí puede tumbar la venta
 *
 * Es la lección de D220 aplicada desde el diseño: un oyente que revienta hace fallar la petición que lo disparó, así que
 * una impresora mal configurada impediría **comandar**. Y comandar es vender.
 *
 * Todo va envuelto: si algo falla, se registra en el log y no se propaga. La contrapartida honesta es que un fallo aquí
 * es silencioso para quien opera —la comanda no sale y el mesero no se entera en el momento—, y lo que lo cubre es la
 * pantalla de trabajos de impresión, no una excepción en la cara del cajero.
 *
 * ## Corre DESPUÉS del commit, en la misma petición
 *
 * No en cola. El papel tiene que estar encolado antes de que el mesero suelte la terminal: en un restaurante, «la
 * comanda saldrá en unos segundos» no es una respuesta. El único efecto asíncrono de esta iteración es el descuento de
 * inventario (§6.2), y es asíncrono porque puede esperar.
 *
 * ## Y por qué recupera el contexto de tenant
 *
 * El evento del kernel lleva `tenantId` como primitivo (D231), no el contexto. Los oyentes corren después del commit y
 * podrían correr con el contexto ya olvidado, así que se restablece explícitamente — si no, el global scope no
 * encontraría el ticket y el trabajo nunca se encolaría, en silencio.
 */
final readonly class QueueTicketsForPrinting
{
    public function __construct(
        private QueuePrintJob $jobs,
        private TenantContext $tenants,
    ) {}

    public function handleCommanded(PosOrderCommanded $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $ticket = PosTicket::query()->whereKey($event->ticketId)->first();

            if ($ticket !== null) {
                $this->jobs->forTicket($ticket);
            }
        });
    }

    public function handleCancelled(PosItemsCancelled $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $ticket = PosTicket::query()->where('ulid', $event->cancellationTicketUlid)->first();

            if ($ticket !== null) {
                $this->jobs->forTicket($ticket);
            }
        });
    }

    /**
     * El ticket final, al pagar.
     *
     * Sale por la impresora de la caja y no por la de un área: es el comprobante del cliente, no un papel de cocina.
     * Esa elección la hace `QueuePrintJob` a partir del tipo de ticket.
     */
    public function handlePaid(PosAccountPaid $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $ticket = PosTicket::query()->where('ulid', $event->receiptTicketUlid)->first();

            if ($ticket !== null) {
                $this->jobs->forTicket($ticket);
            }
        });
    }

    /**
     * Reimpresión de un ticket que no va a un área (recibo final, precuenta): sale otra copia por la misma impresora.
     *
     * Es el mismo `forTicket` del pago: no se reconstruye el contenido, se vuelve a encolar el ticket tal cual — una
     * reimpresión que dijera algo distinto del original sería lo único que una reimpresión no puede hacer.
     */
    public function handleReprint(PosTicketReprintRequested $event): void
    {
        $this->safely($event->tenantId, function () use ($event): void {
            $ticket = PosTicket::query()->whereKey($event->ticketId)->first();

            if ($ticket !== null) {
                $this->jobs->forTicket($ticket);
            }
        });
    }

    /**
     * Ejecuta con el contexto puesto y sin dejar que nada escape.
     */
    private function safely(int $tenantId, callable $accion): void
    {
        try {
            $this->tenants->runFor($tenantId, $accion);
        } catch (Throwable $e) {
            Log::error('No se pudo encolar la impresión.', [
                'tenant_id' => $tenantId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

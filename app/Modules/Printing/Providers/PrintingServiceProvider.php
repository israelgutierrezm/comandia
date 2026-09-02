<?php

declare(strict_types=1);

namespace App\Modules\Printing\Providers;

use App\Modules\Printing\Domain\Exceptions\PrintJobException;
use App\Modules\Printing\Listeners\PrintEcommerceComandas;
use App\Modules\Printing\Listeners\QueueTicketsForPrinting;
use App\Modules\Shared\Domain\Events\EcommerceOrderAccepted;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Shared\Domain\Events\PosTicketReprintRequested;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Printing`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`, nunca por descubrimiento
 * de disco (D64).
 */
final class PrintingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Lo que se comanda y lo que se cancela, se imprime. Los eventos son del KERNEL con datos primitivos (D231), así
        // que este módulo no conoce el POS por el evento — lo conoce por la FK del trabajo al ticket, que sí está
        // declarada en el registro de módulos.
        Event::listen(PosOrderCommanded::class, [QueueTicketsForPrinting::class, 'handleCommanded']);
        Event::listen(PosItemsCancelled::class, [QueueTicketsForPrinting::class, 'handleCancelled']);

        // Y el ticket final al pagar, que sale por la impresora de la caja: es el comprobante del cliente, no un papel
        // de cocina.
        Event::listen(PosAccountPaid::class, [QueueTicketsForPrinting::class, 'handlePaid']);

        // Reimprimir un recibo (o precuenta): otra copia por la misma impresora. Las comandas se reimprimen por su
        // propio evento (`PosOrderCommanded`, que además las reanuncia en el KDS); esto es para los tickets que no van a
        // un área.
        Event::listen(PosTicketReprintRequested::class, [QueueTicketsForPrinting::class, 'handleReprint']);

        // Un pedido de e-commerce aceptado también imprime sus comandas por área (Tanda D). Mismo mecanismo que el POS,
        // por un evento del kernel: la tienda no se nombra aquí.
        Event::listen(EcommerceOrderAccepted::class, PrintEcommerceComandas::class);

        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Los invariantes de impresión responden 409.
     *
     * «Esa impresora no tiene cajón» o «ese agente no reclamó este trabajo» no son errores de captura: los datos que
     * llegaron son correctos y no hay campo que corregir. Lo que no encaja es el estado de las cosas, y para eso está el
     * 409 — el mismo criterio del resto del sistema.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (PrintJobException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'conflict',
                'title' => $e->getMessage(),
                'status' => 409,
            ], 409);
        });
    }
}

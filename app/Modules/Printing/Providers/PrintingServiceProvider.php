<?php

declare(strict_types=1);

namespace App\Modules\Printing\Providers;

use App\Modules\Printing\Domain\Exceptions\PrintJobException;
use App\Modules\Printing\Listeners\QueueTicketsForPrinting;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
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

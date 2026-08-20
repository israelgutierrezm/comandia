<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Domain\Exceptions\FinanceInvariantException;
use App\Modules\Finance\Listeners\RecordAccountPayments;
use App\Modules\Finance\Listeners\RecordCashSessionMovements;
use App\Modules\Finance\Listeners\SeedFinanceDefaultsForNewTenant;
use App\Modules\Shared\Domain\Events\PosAccountPaid;
use App\Modules\Shared\Domain\Events\PosSessionOpened;
use App\Modules\Shared\Domain\Events\PosWithdrawalRegistered;
use App\Modules\Tenancy\Events\TenantProvisioned;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Finance`.
 *
 * Lo registra `ModuleServiceProvider` a partir del registro declarativo de `config/comandia.php`, nunca por
 * descubrimiento de disco (D64).
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sin métodos de pago no se cobra y sin categorías de gasto el primer gasto urgente acaba en una
        // categoría inventada al vuelo: las dos cosas se siembran con el alta del negocio.
        Event::listen(TenantProvisioned::class, SeedFinanceDefaultsForNewTenant::class);

        // Lo que ocurre en una caja se asienta en el diario. Los eventos son del KERNEL con datos primitivos (D231), así
        // que este módulo no conoce el POS ni el POS lo conoce a él.
        //
        // Se registran los métodos y no la clase: un oyente con dos métodos para dos eventos distintos es más claro que
        // dos clases que comparten el mismo `RecordFinancialMovement` y la misma forma de no tumbar la operación.
        Event::listen(PosSessionOpened::class, [RecordCashSessionMovements::class, 'handleOpened']);
        Event::listen(PosWithdrawalRegistered::class, [RecordCashSessionMovements::class, 'handleWithdrawal']);

        // Y lo que deja una cuenta pagada: la venta, sus pagos por método, la propina con nombre y el cambio. Cuatro
        // tipos de asiento porque cada uno contesta otra pregunta — sumarlos daría un número que ni cuadra el cajón ni
        // mide la venta.
        Event::listen(PosAccountPaid::class, [RecordAccountPayments::class, 'handle']);

        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce los invariantes del módulo a respuestas HTTP.
     *
     * Se registra aquí y no en el manejador global por la regla de dependencias de §2: el kernel no debe conocer las
     * excepciones de cada módulo. Mismo patrón que `Catalog` y `Configuration`.
     *
     * Sin esto, intentar renombrar un método del sistema devuelve **500**. Un 500 dice «el sistema falló»; lo que pasó
     * es que se pidió algo que el negocio no admite, y eso es un 422 con el motivo en español. Es la corrección que la
     * Iteración 3 tuvo que hacer con los motivos de merma (D186), y aquí se hace desde el principio.
     *
     * ## Se reconoce por TIPO y no por el origen en el trace
     *
     * Mi primera versión lanzaba `LogicException` desde el modelo y aquí miraba `getTrace()[0]['class']` para saber si
     * venía de este módulo. Devolvía 500. El motivo es instructivo: los invariantes de modelo se lanzan desde un
     * **closure del despachador de eventos de Eloquent**, así que el primer marco del trace es el despachador y no el
     * modelo. Con una excepción propia del dominio el traductor la reconoce por su tipo, que es lo que el resto del
     * proyecto ya hacía.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (FinanceInvariantException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'validation_error',
                'title' => $e->getMessage(),
                'status' => 422,

                // Bajo una llave que la interfaz pinta como error general del formulario: el invariante es del método
                // completo —su condición de sistema— y no de un campo suelto. Mismo criterio que `Catalog`.
                'errors' => ['finance' => [$e->getMessage()]],
            ], 422);
        });
    }
}

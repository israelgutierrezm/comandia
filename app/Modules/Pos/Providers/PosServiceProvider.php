<?php

declare(strict_types=1);

namespace App\Modules\Pos\Providers;

use App\Modules\Pos\Application\PosCashSessionProbe;
use App\Modules\Pos\Application\PosConsumptionHistory;
use App\Modules\Pos\Application\PosLiveServiceProbe;
use App\Modules\Pos\Application\ResolveAreaRoute;
use App\Modules\Shared\Domain\Contracts\CashSessionProbe;
use App\Modules\Shared\Domain\Contracts\ConsumptionHistoryProvider;
use App\Modules\Shared\Domain\Contracts\LiveServiceProbe;
use App\Modules\Pos\Domain\Exceptions\CashSessionException;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Domain\Exceptions\PosAreaRouteException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use DomainException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Pos\Listeners\BroadcastCommandedOrder;

/**
 * Proveedor del módulo `Pos`.
 *
 * Lo registra `ModuleServiceProvider` desde el registro declarativo de `config/comandia.php`, nunca por descubrimiento
 * de disco (D64).
 */
final class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `scoped` y no `singleton`: el resolutor de ruteo memoriza qué área le toca a cada artículo para no consultar la
        // tabla veinticuatro veces en una orden de doce líneas, y esa memoria tiene que morir con la petición. Un
        // singleton la conservaría entre peticiones —y entre TENANTS en el mismo proceso de Octane—, mandando comandas a
        // la impresora de otro negocio sin que nada falle. Es el peor tipo de error: silencioso y plausible.
        $this->app->scoped(ResolveAreaRoute::class);

        // La respuesta a «¿esta mesa tiene servicio en curso?».
        //
        // El contrato vive en el kernel y lo consume `Floor`, que necesita negarse a liberar a mano una mesa con una
        // cuenta viva encima. La dependencia va invertida a propósito: `Pos` ya depende de `Floor`, y preguntarlo al
        // revés cerraría un ciclo entre el salón y el punto de venta.
        //
        // Sin binding, el contenedor revienta al resolverlo — y eso es lo correcto: es preferible un error ruidoso a un
        // valor por omisión que dijera «no hay servicio» y dejara liberar mesas ocupadas.
        $this->app->bind(LiveServiceProbe::class, PosLiveServiceProbe::class);

        // Y las preguntas sobre sesiones de caja, para `Finance`: un gasto desde caja pertenece a un turno, y el turno
        // lo resuelve el servidor. Misma inversión de dependencia, mismo motivo — `Pos` ya depende de `Finance`.
        $this->app->bind(CashSessionProbe::class, PosCashSessionProbe::class);

        // Y el historial de consumos, para `Customers`: el expediente los pinta pero viven en `pos_accounts`. Tercera
        // sonda con la misma inversión — `Pos` ya depende de `Customers`, así que Customers pregunta y Pos responde.
        $this->app->bind(ConsumptionHistoryProvider::class, PosConsumptionHistory::class);
    }

    public function boot(): void
    {
        $this->mapDomainExceptionsToHttp();

        // Lo comandado va a la pantalla de la cocina Y al piso: comandar no cambia el estado de la mesa, así que sin
        // este segundo aviso la cuenta de artículos que el piso pinta encima se quedaría vieja toda la noche.
        Event::listen(PosOrderCommanded::class, BroadcastCommandedOrder::class);
    }

    /**
     * Los invariantes de caja responden 409 y no 422.
     *
     * «Esta caja ya tiene un turno abierto» o «este turno ya está cerrado» no son errores de captura: los datos que
     * llegaron son correctos y no hay ningún campo que corregir. Lo que no encaja es el ESTADO del negocio, y para eso
     * está el 409 — el mismo criterio con el que D170 eligió 409 para la autorización pendiente y con el que el paso 3
     * lo eligió para el último método de pago activo.
     *
     * Un 422 mandaría al cajero a revisar el formulario, que es el sitio equivocado: lo que tiene que hacer es cerrar el
     * otro turno.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // Las dos excepciones de estado del POS comparten respuesta: son la misma clase de problema —el estado del
        // negocio no admite lo que se pidió— y darles códigos distintos obligaría al cliente a distinguir dos cosas que
        // se resuelven igual, volviendo a cargar.
        foreach ([CashSessionException::class, PosAccountException::class] as $clase) {
            $handler->renderable(function (DomainException $e, Request $request) use ($clase): ?JsonResponse {
                if (! $e instanceof $clase) {
                    return null;
                }

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

        // El ruteo va aparte y con 422, porque es otra clase de problema: no es que el negocio no admita la acción, es
        // que los datos no forman una regla. Un 409 mandaría a quien configura a «volver a cargar», que no arregla nada.
        $handler->renderable(function (PosAreaRouteException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'unprocessable_entity',
                'title' => $e->getMessage(),
                'status' => 422,
                'errors' => ['preparation_area_ulid' => [$e->getMessage()]],
            ], 422);
        });
    }
}

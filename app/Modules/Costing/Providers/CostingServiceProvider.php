<?php

declare(strict_types=1);

namespace App\Modules\Costing\Providers;

use App\Modules\Costing\Console\RebuildCurrentCostsCommand;
use App\Modules\Costing\Domain\Exceptions\CostCycleDetectedException;
use App\Modules\Costing\Domain\Exceptions\RecipeCycleException;
use App\Modules\Costing\Domain\Exceptions\RecipeInvariantException;
use App\Modules\Costing\Events\ArticleCostChanged;
use App\Modules\Costing\Events\RecipeChanged;
use App\Modules\Costing\Listeners\CaptureCostFromPurchaseReceipt;
use App\Modules\Costing\Listeners\RecalculateOnCostChanged;
use App\Modules\Costing\Listeners\RecalculateOnRecipeChanged;
use App\Modules\Costing\Application\CostingProductCostProbe;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Shared\Domain\Contracts\ProductCostProbe;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Costing`.
 *
 * Este módulo declara depender de `Catalog` en `config/comandia.php`, y el candado de fronteras lo
 * impone: lee artículos y presentaciones, y **nunca les escribe** (P1 de la Iteración 2). Aceptar un
 * precio sugerido pasará por el servicio de `Catalog`, que es el dueño de `articles.base_price` y del
 * historial de precios.
 */
final class CostingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El POS pregunta el costo vigente por el kernel al capturar (D322); `Costing` responde. La dependencia se
        // invierte no por un ciclo —no lo hay— sino para que el POS nunca se bloquee: sin costo, el null-object del kernel
        // devuelve "0" y la venta sigue.
        $this->app->bind(ProductCostProbe::class, CostingProductCostProbe::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildCurrentCostsCommand::class,
            ]);
        }

        // La cascada de costos se engancha por EVENTO y no por llamada directa: quien captura un costo o
        // guarda una receta no tiene por qué saber que existe un recálculo (§2, regla 3).
        Event::listen(ArticleCostChanged::class, RecalculateOnCostChanged::class);
        Event::listen(RecipeChanged::class, RecalculateOnRecipeChanged::class);

        // El costo de una compra llega por evento, nunca por llamada de `Purchasing` (ADR-001, §3.2). Aquí se estrena
        // `CostOrigin::Purchase`, que existe desde la Iteración 2 y llevaba una iteración entera sin llamador.
        Event::listen(PurchaseReceiptConfirmed::class, CaptureCostFromPurchaseReceipt::class);

        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce las excepciones de dominio del módulo a respuestas HTTP.
     *
     * Se registra aquí y no en el manejador global por la regla de dependencias de §2: el kernel no debe
     * conocer las excepciones de cada módulo. Mismo patrón que `Configuration` y `Catalog`.
     *
     * Sin esto, intentar guardar una receta con un ciclo devuelve **500**. Y ese caso importa más que la
     * media: el usuario capturó algo que el negocio no admite —"el pan usa masa y la masa usa pan"— y
     * necesita ver el camino del ciclo para arreglarlo. Un 500 dice "el sistema falló" y esconde
     * justamente la información que resuelve el problema.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // El ciclo detectado AL CALCULAR no es un error de esta petición: guardar un ciclo es
        // imposible, así que si el motor encuentra uno es porque las filas llegaron por otro camino
        // —SQL a mano, una importación—. Se responde 409 y no 422: no hay nada en el cuerpo enviado
        // que el usuario pueda corregir, y un 422 le haría buscar el error donde no está.
        $handler->renderable(function (CostCycleDetectedException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'conflict',
                'title' => $e->getMessage(),
                'status' => 409,
            ], 409);
        });

        $handler->renderable(
            fn (RecipeCycleException $e, Request $request): ?JsonResponse => $this->validationProblem(
                $request,
                $e->getMessage(),
                // Bajo `lines` porque es lo que el usuario tocó: el ciclo lo introduce un ingrediente.
                'lines',
            )
        );

        $handler->renderable(
            fn (RecipeInvariantException $e, Request $request): ?JsonResponse => $this->validationProblem(
                $request,
                $e->getMessage(),
                'lines',
            )
        );
    }

    /**
     * Mismo formato que el resto de la API (§8): `type` estable para el código, texto en español para el
     * humano.
     */
    private function validationProblem(Request $request, string $message, string $field): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'type' => 'validation_error',
            'title' => $message,
            'status' => 422,
            'errors' => [$field => [$message]],
        ], 422);
    }
}

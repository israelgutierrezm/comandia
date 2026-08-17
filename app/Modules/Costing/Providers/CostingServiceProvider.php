<?php

declare(strict_types=1);

namespace App\Modules\Costing\Providers;

use App\Modules\Costing\Console\RebuildCurrentCostsCommand;
use App\Modules\Costing\Domain\Exceptions\RecipeCycleException;
use App\Modules\Costing\Domain\Exceptions\RecipeInvariantException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildCurrentCostsCommand::class,
            ]);
        }

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

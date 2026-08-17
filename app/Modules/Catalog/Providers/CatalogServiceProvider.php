<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Domain\Exceptions\ArticleInvariantException;
use App\Modules\Catalog\Domain\Exceptions\IncompatibleUnitDimensionException;
use App\Modules\Catalog\Listeners\SeedDefaultUnitsForNewTenant;
use App\Modules\Tenancy\Events\TenantProvisioned;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Proveedor del módulo `Catalog`.
 *
 * Lo registra `ModuleServiceProvider` a partir del registro declarativo de `config/comandia.php`,
 * nunca por descubrimiento de disco (D64).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // El kernel anuncia el alta de un negocio sin saber quién escucha (§2, regla 3); el catálogo
        // decide que le importa porque sin unidades no se puede capturar ni un artículo.
        Event::listen(TenantProvisioned::class, SeedDefaultUnitsForNewTenant::class);

        $this->mapDomainExceptionsToHttp();
    }

    /**
     * Traduce las excepciones de dominio del módulo a respuestas HTTP.
     *
     * Se registra aquí y no en el manejador global por la regla de dependencias de §2: el kernel no
     * debe conocer las excepciones de cada módulo. Mismo patrón que `Configuration` en la Iteración 1.
     *
     * Sin esto, romper un invariante devuelve **500**. Un 500 dice "el sistema falló"; lo que pasó es
     * que se pidió algo que el negocio no admite, y eso es un 422 con el motivo en español. Además,
     * los 500 falsos ensucian el monitoreo con ruido que nadie puede accionar.
     */
    private function mapDomainExceptionsToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(
            fn (ArticleInvariantException $e, Request $request): ?JsonResponse => $this->validationProblem(
                $request,
                $e->getMessage(),
                // El invariante es del artículo completo —la combinación de capacidades, precio y
                // categoría— y no de un campo suelto, así que se reporta bajo una llave que la UI
                // pinta como error general del formulario en lugar de colgarla del campo equivocado.
                'article',
            )
        );

        $handler->renderable(
            fn (IncompatibleUnitDimensionException $e, Request $request): ?JsonResponse => $this->validationProblem(
                $request,
                $e->getMessage(),
                'unit_ulid',
            )
        );
    }

    /**
     * Mismo formato que el resto de la API (§8): `type` estable para el código, `title` y `errors`
     * en español para el humano.
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

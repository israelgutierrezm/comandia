<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Application\Consumption\NullConsumptionHistoryProvider;
use App\Modules\Shared\Application\Costing\NullProductCostProbe;
use App\Modules\Shared\Application\Promotions\NullPromotionResolver;
use App\Modules\Shared\Domain\Contracts\ConsumptionHistoryProvider;
use App\Modules\Shared\Domain\Contracts\ProductCostProbe;
use App\Modules\Shared\Domain\Contracts\PromotionResolver;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Domain\Tenancy\TenantScope;
use App\Modules\Shared\Http\Middleware\EnsureModuleActive;
use App\Modules\Shared\Http\Middleware\EnsurePermission;
use App\Modules\Shared\Http\Middleware\EnsureWritePermission;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Modules\Shared\Domain\Events\TableStateChanged;
use App\Modules\Shared\Listeners\BroadcastFloorChanges;

/**
 * Registro del shared kernel.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons de contexto: un request, un tenant, un contexto
        // (ARQUITECTURA_MAESTRA §3). Si se resolvieran instancias nuevas por inyección,
        // el global scope leería un contexto vacío y el aislamiento dependería del azar
        // de quién resolvió primero.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(ContextHolder::class);

        // También singleton, y no por rendimiento: así todos los modelos comparten
        // exactamente la misma instancia del scope y el test estructural puede
        // compararla por identidad además de por clase.
        $this->app->singleton(
            TenantScope::class,
            fn ($app): TenantScope => new TenantScope($app->make(TenantContext::class)),
        );

        $this->app->singleton(
            ModuleGate::class,
            fn ($app): ModuleGate => new ModuleGate(
                $app->make(TenantContext::class),
                $app->make(Cache::class),
            ),
        );

        $this->app->singleton(
            Authorize::class,
            fn ($app): Authorize => new Authorize(
                $app->make(ContextHolder::class),
                $app->make(Cache::class),
                $app->make(ModuleGate::class),
            ),
        );

        $this->app->bind(
            DocumentNumberAllocator::class,
            fn ($app): DocumentNumberAllocator => new DocumentNumberAllocator(
                DB::connection(),
                $app->make(TenantContext::class),
            ),
        );

        // El resolver de promociones por OMISIÓN es el null-object: «ninguna promoción». `PromotionsServiceProvider` lo
        // reemplaza por el motor real cuando el módulo está presente (se registra después, siendo `operations`). Así el
        // POS siempre tiene a quién preguntar y nunca se bloquea, aunque `Promotions` falte o falle (§6).
        $this->app->bind(PromotionResolver::class, NullPromotionResolver::class);

        // El historial de consumos del cliente lo responde `Pos` (invierte la dependencia por el kernel, como
        // `LiveServiceProbe`). Por omisión, «sin consumos»: `PosServiceProvider` enlaza el proveedor real, que se registra
        // después. Así el expediente se pinta aunque una prueba no levante `Pos`.
        $this->app->bind(ConsumptionHistoryProvider::class, NullConsumptionHistoryProvider::class);

        // El costo vigente de un artículo lo responde `Costing`. Por omisión, `"0"`: el POS congela el costo al capturar
        // (D322) pero NUNCA se bloquea por no saberlo (§6). `CostingServiceProvider` enlaza el proveedor real.
        $this->app->bind(ProductCostProbe::class, NullProductCostProbe::class);

        // El registro de definiciones de reporte (ADR-007). Singleton: cada módulo dueño registra sus reportes aquí en su
        // `boot()`, y el motor de `Reporting` los lee. Una sola instancia compartida, como el catálogo de eventos.
        $this->app->singleton(ReportRegistry::class);
    }

    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->mapAuthorizationRequiredToHttp();

        // El piso en vivo. Un oyente sin `Event::listen` no falla: NO CORRE, y el efecto simplemente no ocurre — por
        // eso hay un candado que lo vigila desde la Iteración 3.
        Event::listen(TableStateChanged::class, BroadcastFloorChanges::class);
    }

    /**
     * El 409 `authorization_required`, traducido UNA vez para todo el sistema (ADR-008).
     *
     * ## Por qué está en el kernel y no en cada módulo
     *
     * Nació en `Inventory`, con las mermas (D170) y el cierre de conteos. Al llegar el POS —retiros de caja, descuentos,
     * cancelación post-comanda, cajón de dinero— habría hecho falta o duplicar este bloque en `Pos`, o que `Pos`
     * importara la excepción base de `Inventory`. Lo primero da dos contratos que se desvían; lo segundo mete una flecha
     * de dependencia entre dos módulos que no tienen nada que ver.
     *
     * Es el mismo razonamiento de D231 aplicado a las excepciones: un contrato que cruza módulos vive donde no depende
     * de nadie. Las subclases se quedan en su módulo —cada una sabe de qué operación habla— y la traducción es una.
     *
     * ## 409 y no 422, otra vez
     *
     * No hay nada en el cuerpo que corregir: los datos son correctos y la operación es legítima. Lo que falta es la
     * firma de otra persona. Un 422 mandaría al usuario a revisar los campos, que es el sitio equivocado, y el código
     * `authorization_required` es lo que la interfaz usa para abrir el diálogo del PIN en lugar de pintar un error.
     */
    private function mapAuthorizationRequiredToHttp(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        $handler->renderable(function (RequiresAuthorizationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return new JsonResponse([
                'type' => 'authorization_required',
                'title' => $e->getMessage(),
                'status' => 409,

                // El permiso viaja en la respuesta para que el cliente no lleve su propia tabla de «qué permiso pide
                // cada operación» (D170), y para que `ApiError` lo exponga tal cual (D223).
                'required_permission' => $e->requiredPermission(),
            ], 409);
        });
    }

    /**
     * Alias de middleware del kernel.
     *
     * `can` se REEMPLAZA a propósito: el `can` de Laravel evalúa la suma de roles del
     * usuario y aquí opera el rol activo (D9). Registrarlo con el nombre que un
     * desarrollador de Laravel va a escribir por instinto es deliberado —conviene que
     * ese instinto lleve al camino correcto en lugar de al que concede permisos de más—.
     */
    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('can', EnsurePermission::class);
        $router->aliasMiddleware('can.write', EnsureWritePermission::class);
        $router->aliasMiddleware('module', EnsureModuleActive::class);
    }
}

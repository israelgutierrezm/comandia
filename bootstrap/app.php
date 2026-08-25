<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Platform\Http\Middleware\SharePlatformInertia;
use App\Modules\Platform\Http\Middleware\UsePlatformSession;
use App\Modules\Shared\Http\ApiProblem;
use App\Modules\Shared\Http\Middleware\EnsureModuleActive;
use App\Modules\Shared\Http\Middleware\ResolveTenantContext;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        // API versionada desde el día uno (ARQUITECTURA_MAESTRA §8). Las rutas
        // de cada módulo se registran con este mismo prefijo desde
        // App\Providers\ModuleServiceProvider.
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vue 3 + Inertia comparten el shell de la aplicación autenticada. El orden
        // efectivo lo fija la lista de prioridad de más abajo.
        $middleware->web(append: [
            ResolveTenantContext::class,
            HandleInertiaRequests::class,
        ]);

        // Sanctum: la SPA de Vue se autentica por sesión (misma cookie que web),
        // la app Flutter por token. Ambas contra el mismo /api/v1.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            ResolveTenantContext::class,
        ]);

        // Alias para gatear un grupo de rutas por módulo activable: `->middleware('module:Ecommerce')`.
        // Segunda de las tres barreras de §2 regla 4 (las otras: autorización y guard de navegación). Estrena su
        // primer uso real en la Iteración 8.
        $middleware->alias([
            'module' => EnsureModuleActive::class,
        ]);

        // El webhook de pago lo llama la pasarela, sin token CSRF: se exime. La autenticidad la da la FIRMA que cada
        // pasarela verifica en su `parseWebhook`, no el CSRF (Iteración 8, Tanda C).
        $middleware->validateCsrfTokens(except: [
            't/*/webhook/*',
        ]);

        // ---------------------------------------------------------------------
        // ORDEN: el contexto se resuelve ANTES de SubstituteBindings.
        //
        // No es una preferencia. El binding de ruta resuelve `{branch}` consultando el
        // modelo, y todo modelo de dominio lleva global scope de tenant: sin contexto, la
        // consulta lanza excepción y cualquier endpoint con parámetro de ruta devuelve 500.
        //
        // Se usa la lista de prioridad y no `prepend` en el grupo porque en `web` el
        // contexto necesita que StartSession ya haya corrido —el tenant de la SPA vive en
        // la sesión—, así que tiene que quedar EN MEDIO: después de la sesión y antes del
        // binding. La lista de prioridad es el mecanismo que Laravel ofrece justo para eso.
        // ---------------------------------------------------------------------
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenantContext::class,
        );

        // Superficies públicas sin autenticación: menú QR (/m/{slug}) y tienda
        // en línea (/t/{slug}). Grupo propio y no `web` porque no comparten
        // guardias de sesión autenticada ni deben heredar middleware que se
        // agregue a la administración. Necesitan sesión porque el carrito de
        // e-commerce vive en ella (Iteración 9).
        $middleware->group('public', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            'throttle:public',
        ]);

        // La super administración del SaaS. Grupo propio y NO `web`: no lleva contexto de tenant (estas pantallas no
        // pertenecen a ningún negocio) y su cookie de sesión es distinta —`UsePlatformSession` la renombra antes de
        // arrancar la sesión—, así que iniciar sesión como super admin es del todo independiente de cualquier negocio.
        // El guard es `platform` (otra tabla), y `EnsureSuperAdmin` protege lo que va detrás del acceso.
        $middleware->group('platform', [
            UsePlatformSession::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            SharePlatformInertia::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Formato de error uniforme estilo RFC 7807 (ARQUITECTURA_MAESTRA §8): un solo
        // manejador de errores en el cliente en lugar de uno por endpoint.
        ApiProblem::register($exceptions);
    })->create();

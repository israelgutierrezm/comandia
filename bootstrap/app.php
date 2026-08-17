<?php

use App\Http\Middleware\HandleInertiaRequests;
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
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Vue 3 + Inertia comparten el shell de la aplicación autenticada.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Sanctum: la SPA de Vue se autentica por sesión (misma cookie que web),
        // la app Flutter por token. Ambas contra el mismo /api/v1.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

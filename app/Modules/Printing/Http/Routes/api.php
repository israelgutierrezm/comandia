<?php

declare(strict_types=1);

use App\Modules\Printing\Http\Controllers\PrintAgentController;
use App\Modules\Printing\Http\Controllers\PrintAgentJobController;
use App\Modules\Printing\Http\Controllers\PrintJobController;
use App\Modules\Printing\Http\Middleware\AuthenticatePrintAgent;
use App\Modules\Shared\Http\Middleware\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Printing — /api/v1
|--------------------------------------------------------------------------
|
| ## DOS grupos con DOS identidades, y por eso están separados
|
| Las de `print-agent/` las autentica el token de un agente: un proceso que corre en una computadora de cocina, no tiene
| rol activo y sólo puede reclamar e informar trabajos de SU sucursal (§9.3). Las demás las autentica una sesión con
| permisos, como el resto del sistema.
|
| Mezclarlas obligaría a un solo middleware a decidir cuál de las dos identidades aplica en cada ruta, que es como se
| cuelan los agujeros: basta que una ruta nueva caiga en el grupo equivocado para que un token de cocina abra la API.
|
*/

// ---- El contrato del agente (§9.4) ----
//
// No pasan por `auth:sanctum` ni por permisos del rol activo: la identidad es el token del agente y el alcance es su
// sucursal. Está declarado como excepción en `RoutePermissionTest`.
//
// ## Y se EXCLUYE `ResolveTenantContext`
//
// Ese middleware está en el grupo `api`, así que corre en TODA ruta de `/api/*`, y resuelve el contexto de una PERSONA a
// partir de su sesión. Un agente no es una persona: su tenant sale de su propio token y lo fija `AuthenticatePrintAgent`.
// Dejar que corriera antes significaría resolver el contexto dos veces con dos criterios distintos, y el segundo
// pisando al primero.
//
// Honestidad sobre cómo llegó esta línea: la puse persiguiendo un 401 «No has iniciado sesión» que resultó no venir de
// aquí, sino de `Sanctum\AuthenticateSession` —el cliente de pruebas arrastraba el `Referer` de una petición anterior y
// Sanctum trataba la del agente como si fuera un navegador—. Eso se arregló en el arnés, con `actingAsPrintAgent()`. La
// exclusión se queda porque se sostiene sola, no porque arreglara aquello.
Route::middleware(AuthenticatePrintAgent::class)
    ->withoutMiddleware(ResolveTenantContext::class)
    ->prefix('print-agent')->group(function (): void {
    Route::get('jobs/next', [PrintAgentJobController::class, 'next'])->name('print-agent.jobs.next');

    Route::post('jobs/{printJob}/printed', [PrintAgentJobController::class, 'printed'])
        ->name('print-agent.jobs.printed');

    Route::post('jobs/{printJob}/failed', [PrintAgentJobController::class, 'failed'])
        ->name('print-agent.jobs.failed');
});

// ---- La pantalla de administración ----
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('print-jobs', [PrintJobController::class, 'index'])
        ->middleware('can:printing.jobs.view')->name('print-jobs.index');

    Route::get('print-jobs/{printJob}', [PrintJobController::class, 'show'])
        ->middleware('can:printing.jobs.view')->name('print-jobs.show');

    // Reintentar exige permiso propio y no el de ver: volver a sacar un papel de la cocina puede hacer que se prepare
    // la comida dos veces.
    Route::post('print-jobs/{printJob}/retry', [PrintJobController::class, 'retry'])
        ->middleware('can.write:printing.jobs.retry')->name('print-jobs.retry');

    // El cajón. Además del permiso, exige PIN dentro del controlador (§6.3, ADR-008).
    Route::post('printers/{printer}/open-drawer', [PrintJobController::class, 'openDrawer'])
        ->middleware('can.write:pos.cash_drawer.open')->name('printers.open-drawer');

    // ---- Agentes ----
    //
    // Con el permiso de las IMPRESORAS y sin inventar uno nuevo: un agente es la contraparte de una impresora en la
    // misma infraestructura —quien configura a dónde imprimir configura quién imprime— y un permiso nuevo no existiría
    // para los negocios que ya corren, así que su ruta respondería 403 para todo el mundo hasta que alguien acordara
    // correr `comandia:permissions:sync` (D219).
    //
    // La tensión honesta: el token de un agente es una CREDENCIAL, y esto deja que quien puede cambiar la IP de una
    // impresora también pueda emitir una. En un negocio de comida son la misma persona —el dueño o el gerente—, y si
    // alguna vez hacen falta dos manos distintas, separarlo es agregar un permiso, no rehacer nada.
    Route::get('print-agents', [PrintAgentController::class, 'index'])
        ->middleware('can:organization.printers.view')->name('print-agents.index');

    Route::post('print-agents', [PrintAgentController::class, 'store'])
        ->middleware('can.write:organization.printers.manage')->name('print-agents.store');

    Route::post('print-agents/{printAgent}/rotate-token', [PrintAgentController::class, 'rotate'])
        ->middleware('can.write:organization.printers.manage')->name('print-agents.rotate');

    Route::post('print-agents/{printAgent}/archive', [PrintAgentController::class, 'archive'])
        ->middleware('can.write:organization.printers.manage')->name('print-agents.archive');
});

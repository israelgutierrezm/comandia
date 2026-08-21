<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Controllers\ContextController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del shared kernel — /api/v1
|--------------------------------------------------------------------------
|
| Registradas por App\Providers\ModuleServiceProvider con el prefijo `api/v1` y el
| middleware `api`.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // El contexto operativo. Sin permiso adicional a propósito: preguntar quién eres y
    // qué puedes hacer no requiere permiso —es la respuesta a esa pregunta la que los
    // contiene— y exigirlo crearía un huevo y la gallina en el arranque del shell.
    Route::get('context', ContextController::class)->name('context.show');

    /*
     * La autorización de canales privados, DENTRO de la API versionada.
     *
     * Laravel registra por su cuenta `POST /broadcasting/auth` en el grupo `web`, y ahí no sirve para esta aplicación:
     * la SPA se autentica con la cookie de Sanctum contra `/api/v1`, y en la ruta suelta de `web` el broadcaster no
     * encuentra usuario y responde 403 **antes de consultar el canal**. Lo encontré escribiendo la prueba de
     * autorización: se rechazaba también el canal propio, y el guardián nunca llegaba a ejecutarse.
     *
     * Aquí hereda lo que necesita: Sanctum, el contexto de tenant y el rol activo — que es exactamente lo que las tres
     * comprobaciones de `ChannelAccess` van a preguntar.
     */
    Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request))
        ->name('broadcasting.auth');
});

<?php

declare(strict_types=1);

use App\Modules\Pos\Http\Controllers\CashSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Pos — /api/v1
|--------------------------------------------------------------------------
|
| ## Los permisos de caja son cuatro, y no uno
|
| Abrir, precortar, cerrar y retirar tienen permiso propio (§6.3). No es burocracia: el cajero abre y cierra su turno,
| pero el RETIRO es dinero saliendo del cajón durante el servicio y exige además el PIN de un superior. Y el precorte
| ciego puede ser de otra persona —quien supervisa— sin que eso le dé poder de cerrar.
|
| ## Sin borrado ni edición
|
| Una sesión no se borra ni se edita: se abre, se declara, se cierra. Su corte se calcula del diario, así que un
| `UPDATE` sobre el turno cambiaría la historia sin cambiar el dinero.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('pos-sessions', [CashSessionController::class, 'index'])
        ->middleware('can:pos.sessions.open')->name('pos-sessions.index');

    // El turno abierto de una terminal. Es la PRIMERA petición de la pantalla de caja: sin turno no se puede cobrar,
    // así que la interfaz necesita saberlo antes de pintar nada.
    Route::get('terminals/{terminal}/current-session', [CashSessionController::class, 'current'])
        ->middleware('can:pos.sessions.open')->name('terminals.current-session');

    Route::get('pos-sessions/{posSession}', [CashSessionController::class, 'show'])
        ->middleware('can:pos.sessions.open')->name('pos-sessions.show');

    Route::post('pos-sessions', [CashSessionController::class, 'open'])
        ->middleware('can.write:pos.sessions.open')->name('pos-sessions.store');

    // Declarar sirve para el precorte Y para el cierre: la forma es idéntica y el momento va en el cuerpo. Dos
    // endpoints obligarían a la interfaz a elegir cuál llamar por algo que ya está diciendo.
    Route::post('pos-sessions/{posSession}/declarations', [CashSessionController::class, 'declare'])
        ->middleware('can.write:pos.sessions.precount')->name('pos-sessions.declare');

    // Retirar exige su permiso Y el PIN de un superior: responde 409 `authorization_required` cuando falta.
    Route::post('pos-sessions/{posSession}/withdrawals', [CashSessionController::class, 'withdraw'])
        ->middleware('can.write:pos.sessions.withdraw')->name('pos-sessions.withdraw');

    Route::post('pos-sessions/{posSession}/close', [CashSessionController::class, 'close'])
        ->middleware('can.write:pos.sessions.close')->name('pos-sessions.close');
});

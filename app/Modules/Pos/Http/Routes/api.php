<?php

declare(strict_types=1);

use App\Modules\Pos\Http\Controllers\CashSessionController;
use App\Modules\Pos\Http\Controllers\PosAccountController;
use App\Modules\Pos\Http\Controllers\PosAreaRouteController;
use App\Modules\Pos\Http\Controllers\PosTicketController;
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

    // ---- Cuentas (D28, §6.3) ----
    //
    // Abrir y capturar van con `pos.orders.create`, que es el permiso del MESERO: su trabajo es tomar la orden. Cobrar
    // es otro permiso y llega en el paso 10.
    //
    // Y REABRIR tiene el suyo (`pos.accounts.reopen`) porque deshace un total que alguien ya vio impreso.
    Route::get('pos-accounts', [PosAccountController::class, 'index'])
        ->middleware('can:pos.orders.create')->name('pos-accounts.index');

    Route::get('pos-accounts/{posAccount}', [PosAccountController::class, 'show'])
        ->middleware('can:pos.orders.create')->name('pos-accounts.show');

    Route::post('pos-accounts', [PosAccountController::class, 'store'])
        ->middleware('can.write:pos.orders.create')->name('pos-accounts.store');

    Route::post('pos-accounts/{posAccount}/orders', [PosAccountController::class, 'capture'])
        ->middleware('can.write:pos.orders.create')->name('pos-accounts.capture');

    Route::post('pos-accounts/{posAccount}/bill-request', [PosAccountController::class, 'requestBill'])
        ->middleware('can.write:pos.accounts.request_bill')->name('pos-accounts.bill-request');

    // Cerrar la cuenta es fijar el total para cobrarla, así que va con el permiso de COBRAR: quien no puede cobrar no
    // tiene por qué poder dejar una cuenta lista para que otro la cobre con un total que él fijó.
    Route::post('pos-accounts/{posAccount}/close', [PosAccountController::class, 'close'])
        ->middleware('can.write:pos.accounts.charge')->name('pos-accounts.close');

    Route::post('pos-accounts/{posAccount}/reopen', [PosAccountController::class, 'reopen'])
        ->middleware('can.write:pos.accounts.reopen')->name('pos-accounts.reopen');

    Route::post('pos-accounts/{posAccount}/cancel', [PosAccountController::class, 'cancel'])
        ->middleware('can.write:pos.items.cancel_commanded')->name('pos-accounts.cancel');

    // ---- Comandar y cancelar items ----

    Route::post('pos-accounts/{posAccount}/orders/{orderUlid}/command', [PosAccountController::class, 'command'])
        ->middleware('can.write:pos.orders.send_to_area')->name('pos-accounts.command');

    // La ruta pide el permiso de cancelar lo NO comandado, que es el mínimo para intentar la acción. Lo comandado
    // escala por PIN dentro del servicio, con `pos.items.cancel_commanded` (ADR-008).
    //
    // Al revés no funcionaría: exigir en la ruta el permiso de cancelar comandado impediría a un mesero corregir su
    // propia captura, que es la operación más común del turno y la que no necesita autorización de nadie.
    Route::post('pos-accounts/{posAccount}/items/cancel', [PosAccountController::class, 'cancelItems'])
        ->middleware('can.write:pos.items.cancel_uncommanded')->name('pos-accounts.items.cancel');

    // ---- Cobrar ----
    //
    // El permiso es de COBRAR y no de capturar: son dos trabajos distintos y en muchos negocios dos personas. Un mesero
    // captura toda la noche sin tocar dinero.
    Route::post('pos-accounts/{posAccount}/payments', [PosAccountController::class, 'charge'])
        ->middleware('can.write:pos.accounts.charge')->name('pos-accounts.charge');

    // ---- Operaciones de cuenta (§4.5) ----
    //
    // Cada una con su permiso, y no con uno solo de «operar cuentas»: dividir es rutina de un mesero, juntar cambia de
    // titular y mover items entre cuentas es la operación por la que se va la mercancía en un bar. Un negocio querrá
    // repartirlas distinto.
    Route::post('pos-accounts/{posAccount}/split', [PosAccountController::class, 'split'])
        ->middleware('can.write:pos.accounts.split')->name('pos-accounts.split');

    Route::post('pos-accounts/{posAccount}/move-items', [PosAccountController::class, 'moveItems'])
        ->middleware('can.write:pos.accounts.move_items')->name('pos-accounts.move-items');

    Route::post('pos-accounts/{posAccount}/merge', [PosAccountController::class, 'merge'])
        ->middleware('can.write:pos.accounts.merge')->name('pos-accounts.merge');

    // Mover la cuenta de mesa. Con el permiso de MESAS del salón y no con uno del POS: quien puede unir mesas es quien
    // decide dónde se sienta la gente.
    Route::post('pos-accounts/{posAccount}/table', [PosAccountController::class, 'moveToTable'])
        ->middleware('can.write:floor.tables.join')->name('pos-accounts.move-table');

    // ---- Descuentos y cortesías ----
    //
    // La ruta pide `pos.orders.create` —basta estar operando el punto de venta— y el permiso REAL lo exige el PIN
    // dentro del servicio: `pos.discounts.apply_item`, `apply_account` o `courtesy` según el caso (ADR-008).
    //
    // Al revés no funcionaría: exigir el permiso de descuento en la ruta impediría que un mesero pidiera la
    // autorización de su gerente, que es exactamente el flujo que §6.3 describe. El permiso lo tiene la sesión; el PIN,
    // la persona.
    Route::post('pos-accounts/{posAccount}/discounts', [PosAccountController::class, 'discount'])
        ->middleware('can.write:pos.orders.create')->name('pos-accounts.discount');

    // ---- Lo que se imprimió ----

    Route::get('pos-tickets', [PosTicketController::class, 'index'])
        ->middleware('can:pos.orders.create')->name('pos-tickets.index');

    Route::get('pos-tickets/{posTicket}', [PosTicketController::class, 'show'])
        ->middleware('can:pos.orders.create')->name('pos-tickets.show');

    // Reimprimir exige el permiso de comandar y no el de ver: sacar otra vez un papel de la cocina puede hacer que se
    // prepare la comida dos veces.
    Route::post('pos-tickets/{posTicket}/reprint', [PosTicketController::class, 'reprint'])
        ->middleware('can.write:pos.orders.send_to_area')->name('pos-tickets.reprint');

    // ---- Ruteo a áreas de preparación (D240) ----
    //
    // Con el permiso de las ÁREAS, no con uno nuevo del POS: configurar qué va a la cocina y qué a la barra es
    // configurar las áreas. Y un permiso nuevo no existiría para los negocios que ya corren (D219).

    Route::get('pos-area-routes', [PosAreaRouteController::class, 'index'])
        ->middleware('can:organization.preparation_areas.view')->name('pos-area-routes.index');

    Route::post('pos-area-routes', [PosAreaRouteController::class, 'store'])
        ->middleware('can.write:organization.preparation_areas.manage')->name('pos-area-routes.store');

    Route::delete('pos-area-routes/{posAreaRoute}', [PosAreaRouteController::class, 'destroy'])
        ->middleware('can.write:organization.preparation_areas.manage')->name('pos-area-routes.destroy');
});

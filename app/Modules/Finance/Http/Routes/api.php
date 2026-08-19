<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\PaymentMethodController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Finance — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así este archivo es la respuesta completa a «¿qué permiso hace
| falta para esto?», que es la pregunta que se hace al auditar.
|
| ## Los dos permisos, y por qué son dos
|
| `view` lo necesita QUIEN COBRA: sin la lista de métodos activos, la pantalla de cobro llega sin con qué cobrar. Está
| en las plantillas de cajero y de mesero con cobro.
|
| `manage` es de quien decide con qué se puede pagar en el negocio, que es una decisión distinta y más restringida. Un
| cajero no debería poder dar de alta un método «Vale de Beto» y cobrar por ahí.
|
| ## Sin borrado
|
| Un método se da de BAJA. `pos_payments` lo citará con `RESTRICT`, así que borrarlo será imposible en cuanto exista un
| cobro — y debe serlo: un pago que no puede decir con qué se pagó no explica nada.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('payment-methods', [PaymentMethodController::class, 'index'])
        ->middleware('can:finance.payment_methods.view')->name('payment-methods.index');

    Route::get('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'show'])
        ->middleware('can:finance.payment_methods.view')->name('payment-methods.show');

    Route::post('payment-methods', [PaymentMethodController::class, 'store'])
        ->middleware('can.write:finance.payment_methods.manage')->name('payment-methods.store');

    Route::patch('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])
        ->middleware('can.write:finance.payment_methods.manage')->name('payment-methods.update');

    // Activar y desactivar comparten endpoint porque son la misma decisión leída en dos direcciones, y separarlas
    // obligaría a la interfaz a saber en cuál está para llamar a la correcta.
    Route::post('payment-methods/{paymentMethod}/toggle', [PaymentMethodController::class, 'toggle'])
        ->middleware('can.write:finance.payment_methods.manage')->name('payment-methods.toggle');
});

<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\ExpenseCategoryController;
use App\Modules\Finance\Http\Controllers\JournalController;
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

    // ---- El diario (ADR-004) ----
    //
    // SÓLO LECTURA, y no es una omisión: al diario escriben únicamente los oyentes de eventos de dominio. Un endpoint
    // de escritura sería la puerta por la que deja de ser auditable, porque permitiría asentar un movimiento sin
    // documento que lo respalde.
    Route::get('financial-movements', [JournalController::class, 'index'])
        ->middleware('can:finance.journal.view')->name('financial-movements.index');

    // ---- Categorías de gasto (§6.5) ----
    //
    // El mismo catálogo para los gastos desde caja y los de fuera: la diferencia entre ellos es de dónde salió el
    // dinero, no en qué se gastó. Se leen con el permiso de registrar gastos —quien captura necesita la lista— y se
    // administran con el de gastos fuera de caja, que es el más restringido de los dos.
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])
        ->middleware('can:finance.expenses.create_from_cash')->name('expense-categories.index');

    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])
        ->middleware('can.write:finance.expenses.create_outside_cash')->name('expense-categories.store');

    Route::patch('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])
        ->middleware('can.write:finance.expenses.create_outside_cash')->name('expense-categories.update');

    Route::post('expense-categories/{expenseCategory}/toggle', [ExpenseCategoryController::class, 'toggle'])
        ->middleware('can.write:finance.expenses.create_outside_cash')->name('expense-categories.toggle');
});

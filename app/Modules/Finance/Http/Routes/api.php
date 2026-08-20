<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\ExpenseCategoryController;
use App\Modules\Finance\Http\Controllers\BankDepositController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\TipSettlementController;
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

    // ---- Gastos (§6.5) ----
    //
    // Ver los gastos usa el permiso del DIARIO: un gasto es un asiento visto desde otro ángulo, y quien puede consultar
    // el diario ya ve el movimiento — esconder la lista sólo obligaría a leerlo en el formato menos legible.
    Route::get('expenses', [ExpenseController::class, 'index'])
        ->middleware('can:finance.journal.view')->name('expenses.index');

    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])
        ->middleware('can:finance.journal.view')->name('expenses.show');

    // Registrar exige el permiso de gasto DESDE CAJA, que es el mínimo. El de fuera de caja lo comprueba el propio
    // endpoint contra el `source` recibido: son dos decisiones distintas —el cajero paga los garrafones, y no por eso
    // debería registrar la renta del local— y un permiso único obligaría a darlos los dos o ninguno.
    Route::post('expenses', [ExpenseController::class, 'store'])
        ->middleware('can.write:finance.expenses.create_from_cash')->name('expenses.store');

    // ---- Depósitos bancarios (§6.5) ----
    //
    // Cierran el retiro: el dinero sale de la caja con un `withdrawal` y entra al banco con esto. Sin la segunda mitad,
    // un retiro deja el efectivo en un limbo declarado.
    Route::get('bank-deposits', [BankDepositController::class, 'index'])
        ->middleware('can:finance.journal.view')->name('bank-deposits.index');

    Route::post('bank-deposits', [BankDepositController::class, 'store'])
        ->middleware('can.write:finance.deposits.create')->name('bank-deposits.store');

    // ---- Liquidación de propinas (§6.6) ----
    //
    // Ver a quién se le debe usa el permiso de LIQUIDAR y no el del diario: es una lista de lo que el negocio le debe a
    // su gente, y no todo el que consulta cuentas tiene por qué verla.
    Route::get('tip-settlements/pending', [TipSettlementController::class, 'pending'])
        ->middleware('can:finance.tips.settle')->name('tip-settlements.pending');

    Route::post('tip-settlements', [TipSettlementController::class, 'store'])
        ->middleware('can.write:finance.tips.settle')->name('tip-settlements.store');
});

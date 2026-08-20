<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Customers\Http\Controllers\CustomerCreditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Customers — /api/v1
|--------------------------------------------------------------------------
|
| ## Dos familias de permisos, y no por burocracia
|
| El CLIENTE lo administra quien atiende (`customers.customers.*`); el CRÉDITO lo administra quien maneja el dinero
| (`finance.customer_credit.*`). Un mesero da de alta a un cliente en dos toques mientras cobra —es el alta express de
| D43— y no por eso debería poder subirle el límite de crédito.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('can:customers.customers.view')->name('customers.index');

    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('can:customers.customers.view')->name('customers.show');

    Route::post('customers', [CustomerController::class, 'store'])
        ->middleware('can.write:customers.customers.manage')->name('customers.store');

    Route::patch('customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('can.write:customers.customers.manage')->name('customers.update');

    // ---- Crédito ----

    Route::get('customers/{customer}/credit-movements', [CustomerCreditController::class, 'statement'])
        ->middleware('can:finance.customer_credit.view')->name('customers.credit.statement');

    Route::patch('customers/{customer}/credit', [CustomerCreditController::class, 'update'])
        ->middleware('can.write:finance.customer_credit.manage')->name('customers.credit.update');

    // El abono entra a la caja, así que exige el permiso de administrar crédito y no el de ver: quien recibe el dinero
    // está moviendo el arqueo del turno.
    Route::post('customers/{customer}/credit-repayments', [CustomerCreditController::class, 'repay'])
        ->middleware('can.write:finance.customer_credit.manage')->name('customers.credit.repay');
});

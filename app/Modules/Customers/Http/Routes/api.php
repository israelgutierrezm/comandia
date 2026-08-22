<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\CustomerAddressController;
use App\Modules\Customers\Http\Controllers\CustomerConsumptionController;
use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Customers\Http\Controllers\CustomerCreditController;
use App\Modules\Customers\Http\Controllers\CustomerFiscalProfileController;
use App\Modules\Customers\Http\Controllers\SatCatalogController;
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

    // ---- Consumos (Iteración 6, §6.6) ----
    //
    // Verlos es parte de ver al cliente. El dato vive en `Pos`, que lo responde por una sonda del kernel (D318): este
    // módulo no consulta `pos_accounts` —cerraría un ciclo, porque `Pos` ya depende de `Customers`—.
    Route::get('customers/{customer}/consumos', CustomerConsumptionController::class)
        ->middleware('can:customers.customers.view')->name('customers.consumos');

    // ---- Expediente: perfiles fiscales y direcciones (Iteración 6, CFDI-ready) ----
    //
    // Verlos es parte de ver al cliente (`customers.customers.view`); administrarlos tiene su propio permiso, el que
    // llevaba dos iteraciones declarado sin ruta.

    Route::get('sat-catalogs', SatCatalogController::class)
        ->middleware('can:customers.fiscal_profiles.manage')->name('customers.sat-catalogs');

    Route::get('customers/{customer}/fiscal-profiles', [CustomerFiscalProfileController::class, 'index'])
        ->middleware('can:customers.customers.view')->name('customers.fiscal-profiles.index');
    Route::post('customers/{customer}/fiscal-profiles', [CustomerFiscalProfileController::class, 'store'])
        ->middleware('can.write:customers.fiscal_profiles.manage')->name('customers.fiscal-profiles.store');
    Route::patch('customers/{customer}/fiscal-profiles/{fiscalProfile}', [CustomerFiscalProfileController::class, 'update'])
        ->middleware('can.write:customers.fiscal_profiles.manage')->name('customers.fiscal-profiles.update');
    Route::delete('customers/{customer}/fiscal-profiles/{fiscalProfile}', [CustomerFiscalProfileController::class, 'destroy'])
        ->middleware('can.write:customers.fiscal_profiles.manage')->name('customers.fiscal-profiles.destroy');

    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index'])
        ->middleware('can:customers.customers.view')->name('customers.addresses.index');
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])
        ->middleware('can.write:customers.addresses.manage')->name('customers.addresses.store');
    Route::patch('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])
        ->middleware('can.write:customers.addresses.manage')->name('customers.addresses.update');
    Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])
        ->middleware('can.write:customers.addresses.manage')->name('customers.addresses.destroy');
});

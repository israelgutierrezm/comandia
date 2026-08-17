<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\TenantSelectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autenticación y selección de negocio (web)
|--------------------------------------------------------------------------
|
| La autenticación es global al SaaS y la selección de negocio va después: el correo es único en
| toda la plataforma (§4.1), y pedir el negocio antes de saber si la persona existe filtraría qué
| correos pertenecen a qué negocio.
|
*/

Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Aquí también se cambia de negocio sin cerrar sesión: es lo que necesita quien administra
    // dos restaurantes.
    Route::get('negocios', [TenantSelectionController::class, 'show'])->name('tenants.select');
    Route::post('negocios', [TenantSelectionController::class, 'store'])->name('tenants.enter');
});

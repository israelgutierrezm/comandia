<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\Web\InvitationAcceptanceController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\PasswordResetController;
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

    // Recuperar la contraseña (diseño de acceso, fase 2). Públicas y con límite de intentos en sus Form Requests; los
    // nombres son los que el broker de Laravel espera encontrar.
    Route::get('olvide-contrasena', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('olvide-contrasena', [PasswordResetController::class, 'store'])->name('password.email');
    Route::get('restablecer/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('restablecer', [PasswordResetController::class, 'update'])->name('password.update');
});

// Aceptar una invitación (diseño de acceso, fase 3). Ni `guest` ni `auth`: la acepta quien no tiene cuenta, quien la
// tiene y entra con su contraseña, o quien ya tiene la sesión abierta con ese correo. El límite de intentos vive en su
// Form Request.
Route::get('invitacion/{token}', [InvitationAcceptanceController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')->name('invitation.show');
Route::post('invitacion/{token}', [InvitationAcceptanceController::class, 'store'])
    ->where('token', '[A-Za-z0-9]{64}')->name('invitation.accept');

Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Aquí también se cambia de negocio sin cerrar sesión: es lo que necesita quien administra
    // dos restaurantes.
    Route::get('negocios', [TenantSelectionController::class, 'show'])->name('tenants.select');
    Route::post('negocios', [TenantSelectionController::class, 'store'])->name('tenants.enter');
});

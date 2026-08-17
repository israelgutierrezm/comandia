<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\EmployeeProfileController;
use App\Modules\Identity\Http\Controllers\MembershipController;
use App\Modules\Identity\Http\Controllers\MembershipPinController;
use App\Modules\Identity\Http\Controllers\MembershipRoleController;
use App\Modules\Identity\Http\Controllers\PermissionCatalogController;
use App\Modules\Identity\Http\Controllers\PinAuthorizationController;
use App\Modules\Identity\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Identity — /api/v1
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:pin'])->group(function (): void {
    /*
     * Autorización por PIN (ADR-008).
     *
     * `throttle:pin` es parte de la decisión, no un extra: un endpoint que compara PIN de
     * cuatro dígitos sin límite de intentos es un espacio de 10,000 combinaciones abierto a
     * la fuerza bruta. El bloqueo por membresía cubre el ataque dirigido a una persona; el
     * límite por terminal e IP cubre el barrido sobre muchas.
     */
    Route::post('authorizations', PinAuthorizationController::class)->name('authorizations.store');
});

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Personal ----
    Route::get('memberships', [MembershipController::class, 'index'])
        ->middleware('can:identity.users.view')->name('memberships.index');
    Route::get('memberships/{membership}', [MembershipController::class, 'show'])
        ->middleware('can:identity.users.view')->name('memberships.show');
    Route::post('memberships', [MembershipController::class, 'store'])
        ->middleware('can.write:identity.users.create')->name('memberships.store');
    Route::patch('memberships/{membership}', [MembershipController::class, 'update'])
        ->middleware('can.write:identity.users.update')->name('memberships.update');
    Route::post('memberships/{membership}/suspend', [MembershipController::class, 'suspend'])
        ->middleware('can.write:identity.users.suspend')->name('memberships.suspend');
    Route::post('memberships/{membership}/reactivate', [MembershipController::class, 'reactivate'])
        ->middleware('can.write:identity.users.suspend')->name('memberships.reactivate');

    // ---- Roles de una persona ----
    Route::put('memberships/{membership}/roles', [MembershipRoleController::class, 'sync'])
        ->middleware('can.write:identity.memberships.assign_roles')->name('memberships.roles.sync');

    // ---- PIN de terminal ----
    // Tres acciones y no un campo: son tres intenciones distintas y cada una deja su propia
    // entrada en la bitácora.
    Route::put('memberships/{membership}/pin', [MembershipPinController::class, 'set'])
        ->middleware('can.write:identity.memberships.reset_pin')->name('memberships.pin.set');
    Route::post('memberships/{membership}/pin/unlock', [MembershipPinController::class, 'unlock'])
        ->middleware('can.write:identity.memberships.reset_pin')->name('memberships.pin.unlock');
    Route::delete('memberships/{membership}/pin', [MembershipPinController::class, 'remove'])
        ->middleware('can.write:identity.memberships.reset_pin')->name('memberships.pin.remove');

    // ---- Perfil laboral ----
    Route::get('memberships/{membership}/employee-profile', [EmployeeProfileController::class, 'show'])
        ->middleware('can:identity.employee_profiles.view')->name('memberships.profile.show');
    Route::put('memberships/{membership}/employee-profile', [EmployeeProfileController::class, 'upsert'])
        ->middleware('can.write:identity.employee_profiles.manage')->name('memberships.profile.upsert');
    Route::delete('memberships/{membership}/employee-profile', [EmployeeProfileController::class, 'destroy'])
        ->middleware('can.write:identity.employee_profiles.manage')->name('memberships.profile.destroy');

    // ---- Roles del negocio ----
    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('can:identity.roles.view')->name('roles.index');
    Route::get('roles/{role}', [RoleController::class, 'show'])
        ->middleware('can:identity.roles.view')->name('roles.show');
    Route::post('roles', [RoleController::class, 'store'])
        ->middleware('can.write:identity.roles.create')->name('roles.store');
    Route::patch('roles/{role}', [RoleController::class, 'update'])
        ->middleware('can.write:identity.roles.update')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('can.write:identity.roles.delete')->name('roles.destroy');

    // ---- Catálogo de permisos para armar roles ----
    // Con el permiso de VER roles y no el de editarlos: la pantalla que muestra un rol necesita
    // el catálogo para explicar qué significa cada permiso marcado.
    Route::get('permissions', PermissionCatalogController::class)
        ->middleware('can:identity.roles.view')->name('permissions.index');
});

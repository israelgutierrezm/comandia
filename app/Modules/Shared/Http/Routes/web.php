<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Shell de administración (web)
|--------------------------------------------------------------------------
|
| Estas rutas sólo entregan el SHELL: Inertia monta la página y los datos se traen de `/api/v1`
| con Pinia (D59). Por eso ninguna pasa props de dominio — si lo hicieran, la app Flutter
| terminaría consumiendo endpoints que la web no usa, y ésos serían los menos ejercitados.
|
| La autorización de cada pantalla la aplica el propio endpoint que alimenta sus datos; aquí sólo
| se exige sesión y contexto. Un usuario sin permiso ve la pantalla y recibe 403 al pedir los
| datos, y la navegación no le ofrece el enlace: los tres niveles de §4.2 y no uno solo.
|
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');

    Route::get('sucursales', fn () => Inertia::render('Admin/Branches/Index'))->name('branches');
    Route::get('almacenes', fn () => Inertia::render('Admin/Warehouses/Index'))->name('warehouses');
    Route::get('areas', fn () => Inertia::render('Admin/PreparationAreas/Index'))->name('preparation-areas');
    Route::get('terminales', fn () => Inertia::render('Admin/Terminals/Index'))->name('terminals');

    Route::get('personal', fn () => Inertia::render('Admin/Staff/Index'))->name('staff');
    Route::get('roles', fn () => Inertia::render('Admin/Roles/Index'))->name('roles');

    Route::get('configuracion', fn () => Inertia::render('Admin/Settings/Index'))->name('settings');
    Route::get('auditoria', fn () => Inertia::render('Admin/Audit/Index'))->name('audit');
});

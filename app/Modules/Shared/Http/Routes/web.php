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

    // La ficha de una persona: roles, alcance por sucursal y perfil laboral. Recibe el ULID de la ruta
    // por lo mismo que la ficha del artículo — es la respuesta a «¿de quién estamos hablando?», no un
    // dato de dominio.
    Route::get('personal/{persona}', fn (string $persona) => Inertia::render(
        'Admin/Staff/Show',
        ['membershipUlid' => $persona],
    ))->where('persona', '[0-9A-HJKMNP-TV-Z]{26}')->name('staff.show');
    Route::get('roles', fn () => Inertia::render('Admin/Roles/Index'))->name('roles');

    Route::get('configuracion', fn () => Inertia::render('Admin/Settings/Index'))->name('settings');
    Route::get('auditoria', fn () => Inertia::render('Admin/Audit/Index'))->name('audit');
});

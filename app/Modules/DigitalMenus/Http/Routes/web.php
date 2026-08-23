<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Menús digitales (web) — Iteración 8, Tanda A
|--------------------------------------------------------------------------
|
| Pantalla de gestión de menús por sucursal. La autorización real la aplican los endpoints de la API
| (`module:DigitalMenus` + `digital_menus.menus.manage`); el guard de navegación del shell ya oculta el enlace a quien no
| tiene el módulo o el permiso.
|
*/

Route::middleware(['auth'])->prefix('admin/menus')->name('admin.menus.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Menus/Index'))->name('index');
});

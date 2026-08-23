<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Módulos del negocio (web) — Iteración 8, Tanda A
|--------------------------------------------------------------------------
|
| Pantalla del propietario para activar/desactivar Tienda y Menús. La autorización real la aplican los endpoints de la API
| (`tenancy.modules.view`/`.manage`); el guard de navegación del shell ya oculta el enlace a quien no lo tiene.
|
*/

Route::middleware(['auth'])->prefix('admin/modulos')->name('admin.modules.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Modules/Index'))->name('index');
});

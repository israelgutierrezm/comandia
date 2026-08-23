<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Tienda en línea (web) — Iteración 8, Tanda B
|--------------------------------------------------------------------------
|
| Pantalla de configuración de la tienda. La autorización real la aplican los endpoints de la API (`module:Ecommerce` +
| `ecommerce.store.configure`); el guard de navegación del shell oculta el enlace a quien no tiene el módulo o el permiso.
|
*/

Route::middleware(['auth'])->prefix('admin/tienda')->name('admin.store.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Index'))->name('index');
});

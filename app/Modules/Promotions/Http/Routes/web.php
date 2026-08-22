<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Promociones (web)
|--------------------------------------------------------------------------
|
| La ruta sólo entrega la página; la autorización la aplica cada endpoint de la API (D59). La aplicación de promociones
| no tiene pantalla: ocurre sola al cobrar.
|
*/

Route::middleware(['auth'])->prefix('admin/promociones')->name('admin.promotions.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Promotions/Index'))->name('index');
});

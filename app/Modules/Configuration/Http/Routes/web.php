<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Configuración (web)
|--------------------------------------------------------------------------
|
| La pantalla sólo entrega la página; la autorización la aplica cada endpoint (D59).
|
*/

Route::middleware(['auth'])->prefix('admin/correo')->name('admin.mail.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Mail/Index'))->name('index');
});

// Apariencia: el acento de marca del negocio (rediseño, Fase B). La autorización la aplica la API de ajustes.
Route::middleware(['auth'])->prefix('admin/apariencia')->name('admin.appearance.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Appearance/Index'))->name('index');
});

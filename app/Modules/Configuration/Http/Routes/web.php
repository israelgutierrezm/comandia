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

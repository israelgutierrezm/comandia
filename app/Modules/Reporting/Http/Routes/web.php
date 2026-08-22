<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Reportes (web)
|--------------------------------------------------------------------------
|
| Una sola pantalla para todos los reportes: se autoconfigura desde el catálogo y las definiciones de la API. La
| autorización la aplica el motor por reporte (ADR-006), no esta ruta.
|
*/

Route::middleware(['auth'])->prefix('admin/reportes')->name('admin.reports.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Reports/Index'))->name('index');
});

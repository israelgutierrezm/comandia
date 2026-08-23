<?php

declare(strict_types=1);

use App\Modules\DigitalMenus\Http\Controllers\DigitalMenuController;
use App\Modules\DigitalMenus\Http\Controllers\MenuPdfController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Menús digitales — /api/v1 (Iteración 8, Tanda A)
|--------------------------------------------------------------------------
|
| Administración de los menús por sucursal. TODO el grupo va gateado por `module:DigitalMenus`: un negocio sin el módulo no
| ejecuta una sola línea de esto (404), no sólo se le oculta el enlace. Gestionar exige `digital_menus.menus.manage`.
|
*/

Route::middleware(['auth:sanctum', 'module:DigitalMenus'])->group(function (): void {
    Route::get('digital-menus', [DigitalMenuController::class, 'index'])
        ->middleware('can:digital_menus.menus.manage')->name('digital-menus.index');

    Route::post('digital-menus', [DigitalMenuController::class, 'store'])
        ->middleware('can.write:digital_menus.menus.manage')->name('digital-menus.store');

    Route::put('digital-menus/{digitalMenu}', [DigitalMenuController::class, 'update'])
        ->middleware('can.write:digital_menus.menus.manage')->name('digital-menus.update');

    Route::get('digital-menus/{digitalMenu}/pdf', MenuPdfController::class)
        ->middleware('can:digital_menus.pdf.generate')->name('digital-menus.pdf');
});

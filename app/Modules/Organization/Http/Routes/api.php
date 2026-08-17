<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controllers\BranchController;
use App\Modules\Organization\Http\Controllers\PreparationAreaController;
use App\Modules\Organization\Http\Controllers\TerminalController;
use App\Modules\Organization\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Organization — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así el archivo de rutas es la
| respuesta completa a "¿qué permiso hace falta para esto?", que es la pregunta que se hace al
| auditar. Y `can.write` en lugar de `can` en las escrituras deja visible qué endpoints
| escriben — un tenant en `read_only` por impago los recibe con 403 y sigue pudiendo consultar.
|
| El binding de ruta resuelve por ULID (nunca por PK) y con el global scope aplicado, así que
| un identificador de otro tenant produce 404: no se confirma la existencia de un recurso
| ajeno.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Sucursales ----
    Route::get('branches', [BranchController::class, 'index'])
        ->middleware('can:organization.branches.view')->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])
        ->middleware('can:organization.branches.view')->name('branches.show');
    Route::post('branches', [BranchController::class, 'store'])
        ->middleware('can.write:organization.branches.manage')->name('branches.store');
    Route::patch('branches/{branch}', [BranchController::class, 'update'])
        ->middleware('can.write:organization.branches.manage')->name('branches.update');
    Route::post('branches/{branch}/archive', [BranchController::class, 'archive'])
        ->middleware('can.write:organization.branches.manage')->name('branches.archive');

    // ---- Almacenes ----
    Route::get('warehouses', [WarehouseController::class, 'index'])
        ->middleware('can:organization.warehouses.view')->name('warehouses.index');
    Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])
        ->middleware('can:organization.warehouses.view')->name('warehouses.show');
    Route::post('warehouses', [WarehouseController::class, 'store'])
        ->middleware('can.write:organization.warehouses.manage')->name('warehouses.store');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])
        ->middleware('can.write:organization.warehouses.manage')->name('warehouses.update');
    Route::post('warehouses/{warehouse}/archive', [WarehouseController::class, 'archive'])
        ->middleware('can.write:organization.warehouses.manage')->name('warehouses.archive');

    // ---- Áreas de preparación ----
    Route::get('preparation-areas', [PreparationAreaController::class, 'index'])
        ->middleware('can:organization.preparation_areas.view')->name('preparation-areas.index');
    Route::get('preparation-areas/{preparation_area}', [PreparationAreaController::class, 'show'])
        ->middleware('can:organization.preparation_areas.view')->name('preparation-areas.show');
    Route::post('preparation-areas', [PreparationAreaController::class, 'store'])
        ->middleware('can.write:organization.preparation_areas.manage')->name('preparation-areas.store');
    Route::patch('preparation-areas/{preparation_area}', [PreparationAreaController::class, 'update'])
        ->middleware('can.write:organization.preparation_areas.manage')->name('preparation-areas.update');
    Route::post('preparation-areas/{preparation_area}/archive', [PreparationAreaController::class, 'archive'])
        ->middleware('can.write:organization.preparation_areas.manage')->name('preparation-areas.archive');

    // ---- Terminales ----
    Route::get('terminals', [TerminalController::class, 'index'])
        ->middleware('can:organization.terminals.view')->name('terminals.index');
    Route::get('terminals/{terminal}', [TerminalController::class, 'show'])
        ->middleware('can:organization.terminals.view')->name('terminals.show');
    Route::post('terminals', [TerminalController::class, 'store'])
        ->middleware('can.write:organization.terminals.manage')->name('terminals.store');
    Route::patch('terminals/{terminal}', [TerminalController::class, 'update'])
        ->middleware('can.write:organization.terminals.manage')->name('terminals.update');
    Route::post('terminals/{terminal}/archive', [TerminalController::class, 'archive'])
        ->middleware('can.write:organization.terminals.manage')->name('terminals.archive');
});

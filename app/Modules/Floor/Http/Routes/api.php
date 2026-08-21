<?php

declare(strict_types=1);

use App\Modules\Floor\Http\Controllers\FloorPlanController;
use App\Modules\Floor\Http\Controllers\FloorZoneController;
use App\Modules\Floor\Http\Controllers\RestaurantTableController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Floor — /api/v1
|--------------------------------------------------------------------------
|
| ## Tres permisos, y cada uno protege algo distinto
|
| `floor.layouts.view` es de quien ATIENDE: necesita ver el salón para saber dónde sentar. Lo tienen mesero y cajero.
|
| `floor.layouts.edit` es de quien CONFIGURA el salón: dar de alta mesas, crear zonas, mover planos. No es lo mismo que
| atender, y un mesero que pudiera borrar la mesa 4 a media noche sería un problema.
|
| `floor.tables.join` es de quien UNE mesas durante el servicio. Va aparte de las dos anteriores a propósito: unir es
| una operación de piso —la hace el mesero cuando llegan ocho— y no exige poder editar el salón. Al revés, quien
| configura el salón desde la oficina no tiene por qué estar uniendo mesas.
|
| ## Sin borrado de mesas
|
| Una mesa que ya cobró cuentas las tiene citándola. Se puede editar y —cuando exista la operación— desactivar, pero no
| borrar: una cuenta que no puede decir en qué mesa se sirvió pierde la mitad de lo que explica.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // ---- Planos y zonas ----
    Route::get('floor-plans', [FloorPlanController::class, 'index'])
        ->middleware('can:floor.layouts.view')->name('floor-plans.index');

    // El plano COMPLETO —zonas y mesas con su geometría— para que el editor lo dibuje sin cruzar tres respuestas.
    Route::get('floor-plans/{floorPlan}', [FloorPlanController::class, 'show'])
        ->middleware('can:floor.layouts.view')->name('floor-plans.show');

    Route::post('floor-plans', [FloorPlanController::class, 'store'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-plans.store');

    Route::post('floor-plans/{floorPlan}/default', [FloorPlanController::class, 'setDefault'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-plans.default');

    // ---- Mesas ----
    // ---- Zonas (Iteración 5, paso 2) ----
    //
    // Una mesa PERTENECE a una zona, así que la zona es la que ata la mesa al plano: por eso no se puede borrar la
    // última ni una que tenga mesas dentro.
    Route::post('floor-plans/{floorPlan}/zones', [FloorZoneController::class, 'store'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-zones.store');

    Route::patch('floor-zones/{floorZone}', [FloorZoneController::class, 'update'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-zones.update');

    Route::delete('floor-zones/{floorZone}', [FloorZoneController::class, 'destroy'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-zones.destroy');

    Route::get('restaurant-tables', [RestaurantTableController::class, 'index'])
        ->middleware('can:floor.layouts.view')->name('restaurant-tables.index');

    Route::post('restaurant-tables', [RestaurantTableController::class, 'store'])
        ->middleware('can.write:floor.layouts.edit')->name('restaurant-tables.store');

    Route::patch('restaurant-tables/{restaurantTable}', [RestaurantTableController::class, 'update'])
        ->middleware('can.write:floor.layouts.edit')->name('restaurant-tables.update');

    // Unir y separar: permiso de PISO, no de configuración.
    Route::patch('floor-plans/{floorPlan}', [FloorPlanController::class, 'update'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-plans.update');

    // El salón entero en una escritura: doce mesas movidas son un acto, y guardarlas de una en una deja el plano a
    // medias si la quinta falla.
    Route::put('floor-plans/{floorPlan}/layout', [FloorPlanController::class, 'saveLayout'])
        ->middleware('can.write:floor.layouts.edit')->name('floor-plans.layout');

    // Retirar no es borrar: la cuenta de anoche dice en qué mesa se sentó la gente.
    Route::post('restaurant-tables/{restaurantTable}/archive', [RestaurantTableController::class, 'archive'])
        ->middleware('can.write:floor.layouts.edit')->name('restaurant-tables.archive');

    Route::post('restaurant-tables/{restaurantTable}/restore', [RestaurantTableController::class, 'restore'])
        ->middleware('can.write:floor.layouts.edit')->name('restaurant-tables.restore');

    Route::post('restaurant-tables/{restaurantTable}/join', [RestaurantTableController::class, 'join'])
        ->middleware('can.write:floor.tables.join')->name('restaurant-tables.join');

    Route::post('restaurant-tables/{restaurantTable}/separate', [RestaurantTableController::class, 'separate'])
        ->middleware('can.write:floor.tables.join')->name('restaurant-tables.separate');

    // Liberar a mano una mesa que quedó ocupada por error. Va con el permiso de piso porque es una corrección del
    // servicio, no una configuración del salón.
    Route::post('restaurant-tables/{restaurantTable}/free', [RestaurantTableController::class, 'free'])
        ->middleware('can.write:floor.tables.join')->name('restaurant-tables.free');
});

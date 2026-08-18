<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\KardexController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Inventory — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así este archivo es la respuesta completa a «¿qué permiso
| hace falta para esto?», que es la pregunta que se hace al auditar. `can.write` en las escrituras deja visible
| qué endpoints escriben — un tenant en sólo lectura por impago los recibe con 403 y sigue pudiendo consultar.
|
| ## Tres endpoints de escritura, uno por permiso del catálogo
|
| `entries`, `exits` y `adjustments` son tres permisos distintos en el catálogo cerrado (D10), así que son tres
| rutas. Un endpoint único con un campo `kind` no podría declarar su permiso —`can:` recibe uno— y quedaría
| invisible para el candado de D129, que es el que garantiza que ningún endpoint quede abierto.
|
| Y un `kind` libre en el cuerpo permitiría registrar a mano un consumo por venta o una transferencia, que
| pertenecen a un documento: un movimiento sin su documento origen es un movimiento que nadie puede explicar.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Consulta de existencias ----
    //
    // Tres lecturas del mismo saldo porque son tres preguntas distintas, y cada una tiene su índice en
    // `article_stocks`: «¿qué tengo?», «¿dónde está mi queso?» y «¿qué hay en este almacén?».
    Route::get('stocks', [StockController::class, 'index'])
        ->middleware('can:inventory.stock.view')->name('stocks.index');

    Route::get('articles/{article}/stock', [StockController::class, 'forArticle'])
        ->middleware('can:inventory.stock.view')->name('articles.stock');

    Route::get('warehouses/{warehouse}/stocks', [StockController::class, 'forWarehouse'])
        ->middleware('can:inventory.stock.view')->name('warehouses.stocks');

    // ---- Kardex ----
    //
    // Permiso PROPIO, distinto de ver existencias: el saldo dice cuánto hay; el kardex dice **quién lo movió**
    // y cuándo, que es información de control. Un almacenista consulta saldos todo el día; auditar quién ajustó
    // qué es otra cosa.
    Route::get('articles/{article}/kardex', KardexController::class)
        ->middleware('can:inventory.kardex.view')->name('articles.kardex');

    // El catálogo de tipos de movimiento, para que el cliente arme su filtro sin escribir las etiquetas a mano
    // (la lección de D139). Con el permiso de ver el kardex, que es donde se usa.
    Route::get('stock-movement-kinds', [KardexController::class, 'kinds'])
        ->middleware('can:inventory.kardex.view')->name('stock-movement-kinds.index');

    // ---- Movimientos manuales ----
    Route::post('stock-entries', [StockMovementController::class, 'storeEntry'])
        ->middleware('can.write:inventory.entries.create')->name('stock-entries.store');

    Route::post('stock-exits', [StockMovementController::class, 'storeExit'])
        ->middleware('can.write:inventory.exits.create')->name('stock-exits.store');

    // El ajuste es el único que exige dirección explícita y nota escrita: es la confesión de un descuadre, no
    // una operación del negocio.
    Route::post('stock-adjustments', [StockMovementController::class, 'storeAdjustment'])
        ->middleware('can.write:inventory.adjustments.create')->name('stock-adjustments.store');
});

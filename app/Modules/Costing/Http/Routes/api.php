<?php

declare(strict_types=1);

use App\Modules\Costing\Http\Controllers\ArticleCostController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Costing — /api/v1
|--------------------------------------------------------------------------
|
| Las rutas cuelgan de `articles/{article}` aunque el artículo sea de `Catalog`: la URL refleja el
| recurso que el cliente conoce, no el módulo que lo sirve. La dependencia `Costing → Catalog` está
| declarada en el registro de módulos y la impone el candado de fronteras (P1).
|
| El costo es información sensible del negocio y por eso tiene permisos propios: un mesero ve precios
| —los dice en voz alta— y no ve costos. Un almacenista SÍ captura costos: es quien recibe la mercancía
| y ve la factura del proveedor.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // El costo vigente más el promedio del periodo como referencia visual (D14).
    Route::get('articles/{article}/cost', [ArticleCostController::class, 'show'])
        ->middleware('can:costing.costs.view')->name('articles.cost.show');

    // Historial, paginado por cursor: crece con cada compra de cada insumo (§8).
    Route::get('articles/{article}/costs', [ArticleCostController::class, 'index'])
        ->middleware('can:costing.costs.history.view')->name('articles.costs.index');

    Route::post('articles/{article}/costs', [ArticleCostController::class, 'store'])
        ->middleware('can.write:costing.costs.update')->name('articles.costs.store');
});

<?php

declare(strict_types=1);

use App\Modules\Costing\Http\Controllers\ArticleCostController;
use App\Modules\Costing\Http\Controllers\CostBreakdownController;
use App\Modules\Costing\Http\Controllers\RecipeController;
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

    // El desglose del cálculo, línea por línea: un costo sin desglose es un número que nadie cree.
    // Se calcula al leer y no se almacena — es una vista del catálogo en este instante.
    Route::get('articles/{article}/cost-breakdown', CostBreakdownController::class)
        ->middleware('can:costing.costs.view')->name('articles.cost-breakdown');

    // ---- Recetas (D16, D21) ----
    //
    // Un solo recurso por artículo (invariante I1), así que no hay listado ni ULID en la URL. `PUT`
    // reemplaza la receta completa: es una unidad de sentido y se valida entera, incluida la detección
    // de ciclos sobre el estado final.
    Route::get('articles/{article}/recipe', [RecipeController::class, 'show'])
        ->middleware('can:costing.recipes.view')->name('articles.recipe.show');

    Route::put('articles/{article}/recipe', [RecipeController::class, 'update'])
        ->middleware('can.write:costing.recipes.manage')->name('articles.recipe.update');

    Route::delete('articles/{article}/recipe', [RecipeController::class, 'destroy'])
        ->middleware('can.write:costing.recipes.manage')->name('articles.recipe.destroy');
});

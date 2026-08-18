<?php

declare(strict_types=1);

use App\Modules\Costing\Http\Controllers\ArticleCostController;
use App\Modules\Costing\Http\Controllers\ArticlePriceController;
use App\Modules\Costing\Http\Controllers\CostBreakdownController;
use App\Modules\Costing\Http\Controllers\CostImpactController;
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

    // Qué se ve afectado si cambia este costo. Se consulta ANTES de capturar: subir el jitomate de $20 a
    // $60 el kilo cambia el costo de catorce platillos, y quien lo captura tiene derecho a saberlo antes
    // de guardar en lugar de descubrirlo al día siguiente en un reporte de márgenes.
    Route::get('articles/{article}/impact', CostImpactController::class)
        ->middleware('can:costing.recipes.view')->name('articles.impact');

    // ---- Precio sugerido y cambio de precio (D15) ----
    //
    // El endpoint vive aquí y no en `Catalog` porque historizar un cambio de precio exige el snapshot
    // de costeo, y `Catalog` no puede depender de `Costing` (P1). La escritura la sigue haciendo
    // `Catalog\Application\ChangeArticlePrice`: el precio sigue siendo su dato maestro.
    //
    // Los permisos son de los dos mundos, y es correcto: VER el sugerido y el margen es costeo; CAMBIAR
    // el precio es administrar el catálogo comercial.
    Route::get('articles/{article}/suggested-price', [ArticlePriceController::class, 'show'])
        ->middleware('can:costing.suggested_prices.view')->name('articles.suggested-price');

    Route::put('articles/{article}/price', [ArticlePriceController::class, 'update'])
        ->middleware('can.write:catalog.prices.update')->name('articles.price.update');

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

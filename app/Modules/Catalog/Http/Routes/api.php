<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ArticleBranchOverrideController;
use App\Modules\Catalog\Http\Controllers\ArticleCategoryController;
use App\Modules\Catalog\Http\Controllers\ArticleController;
use App\Modules\Catalog\Http\Controllers\ArticlePresentationController;
use App\Modules\Catalog\Http\Controllers\PriceChangeController;
use App\Modules\Catalog\Http\Controllers\TagController;
use App\Modules\Catalog\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Catalog — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así este archivo es la respuesta completa a
| "¿qué permiso hace falta para esto?", que es la pregunta que se hace al auditar. `can.write` en las
| escrituras deja visible qué endpoints escriben — un tenant en sólo lectura por impago los recibe con
| 403 y sigue pudiendo consultar y exportar.
|
| ## Sobre los permisos de lectura de unidades, categorías y etiquetas
|
| Se leen con `catalog.articles.view` y se escriben con su permiso propio. Es deliberado: son datos de
| REFERENCIA del catálogo —cualquiera que capture una receta o consulte un artículo los necesita— y el
| catálogo de permisos es cerrado (D10). Inventar `catalog.units.view` sería agregar tres permisos que
| nadie pidió y que cada tenant tendría que marcar en cada rol para que el sistema funcionara.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Unidades de medida (D22) ----
    Route::get('units', [UnitController::class, 'index'])
        ->middleware('can:catalog.articles.view')->name('units.index');
    Route::get('units/{unit}', [UnitController::class, 'show'])
        ->middleware('can:catalog.articles.view')->name('units.show');
    Route::post('units', [UnitController::class, 'store'])
        ->middleware('can.write:catalog.units.manage')->name('units.store');
    Route::patch('units/{unit}', [UnitController::class, 'update'])
        ->middleware('can.write:catalog.units.manage')->name('units.update');

    // ---- Categorías, dos niveles (D18) ----
    Route::get('article-categories', [ArticleCategoryController::class, 'index'])
        ->middleware('can:catalog.articles.view')->name('article-categories.index');
    Route::get('article-categories/{article_category}', [ArticleCategoryController::class, 'show'])
        ->middleware('can:catalog.articles.view')->name('article-categories.show');
    Route::post('article-categories', [ArticleCategoryController::class, 'store'])
        ->middleware('can.write:catalog.categories.manage')->name('article-categories.store');
    Route::patch('article-categories/{article_category}', [ArticleCategoryController::class, 'update'])
        ->middleware('can.write:catalog.categories.manage')->name('article-categories.update');
    Route::post('article-categories/{article_category}/archive', [ArticleCategoryController::class, 'archive'])
        ->middleware('can.write:catalog.categories.manage')->name('article-categories.archive');

    // ---- Etiquetas libres (D19) ----
    Route::get('tags', [TagController::class, 'index'])
        ->middleware('can:catalog.articles.view')->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])
        ->middleware('can.write:catalog.tags.manage')->name('tags.store');
    // La única entidad del catálogo que se borra de verdad: no aparece en ningún documento.
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])
        ->middleware('can.write:catalog.tags.manage')->name('tags.destroy');

    // ---- Artículos (D17) ----
    Route::get('articles', [ArticleController::class, 'index'])
        ->middleware('can:catalog.articles.view')->name('articles.index');
    Route::get('articles/{article}', [ArticleController::class, 'show'])
        ->middleware('can:catalog.articles.view')->name('articles.show');
    Route::post('articles', [ArticleController::class, 'store'])
        ->middleware('can.write:catalog.articles.manage')->name('articles.store');
    Route::patch('articles/{article}', [ArticleController::class, 'update'])
        ->middleware('can.write:catalog.articles.manage')->name('articles.update');
    // Permiso PROPIO para archivar, distinto del de editar: dejar un artículo fuera del catálogo es
    // una acción de otra naturaleza que cambiarle el nombre.
    Route::post('articles/{article}/archive', [ArticleController::class, 'archive'])
        ->middleware('can.write:catalog.articles.archive')->name('articles.archive');

    // ---- Overrides por sucursal (§6.1) ----
    //
    // La DISPONIBILIDAD es del catálogo; el PRECIO lo sirve `Costing`, porque historizarlo exige el
    // snapshot de costeo (D115). Dos endpoints y no uno: son acciones de naturaleza distinta y con
    // permisos distintos, y unirlas obligaría a que un permiso cubriera al otro.
    Route::get('articles/{article}/branch-overrides', [ArticleBranchOverrideController::class, 'index'])
        ->middleware('can:catalog.prices.view')->name('articles.branch-overrides.index');

    Route::put(
        'articles/{article}/branches/{branch}/availability',
        [ArticleBranchOverrideController::class, 'setAvailability']
    )->middleware('can.write:catalog.articles.manage')->name('articles.branch-availability.update');

    // Historial INMUTABLE de precios (D15). Permiso propio: ver cómo evolucionó un precio es una
    // consulta de control, distinta de ver el precio vigente.
    Route::get('articles/{article}/price-changes', [PriceChangeController::class, 'index'])
        ->middleware('can:catalog.prices.history.view')->name('articles.price-changes.index');

    // ---- Presentaciones de compra (D22) ----
    Route::get('articles/{article}/presentations', [ArticlePresentationController::class, 'index'])
        ->middleware('can:catalog.articles.view')->name('articles.presentations.index');
    Route::post('articles/{article}/presentations', [ArticlePresentationController::class, 'store'])
        ->middleware('can.write:catalog.articles.manage')->name('articles.presentations.store');
    Route::patch('articles/{article}/presentations/{presentation}', [ArticlePresentationController::class, 'update'])
        ->middleware('can.write:catalog.articles.manage')->name('articles.presentations.update');
    Route::post(
        'articles/{article}/presentations/{presentation}/archive',
        [ArticlePresentationController::class, 'archive']
    )->middleware('can.write:catalog.articles.manage')->name('articles.presentations.archive');
});

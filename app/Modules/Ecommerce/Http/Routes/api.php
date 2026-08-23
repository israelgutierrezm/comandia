<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Http\Controllers\ArticleStoreSettingController;
use App\Modules\Ecommerce\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tienda en línea — /api/v1 (Iteración 8, Tanda B)
|--------------------------------------------------------------------------
|
| Administración de la tienda. TODO el grupo va gateado por `module:Ecommerce`: un negocio sin la tienda no ejecuta una
| sola línea de esto. Configurar la tienda y los ajustes por artículo exige `ecommerce.store.configure`.
|
*/

Route::middleware(['auth:sanctum', 'module:Ecommerce'])->group(function (): void {
    Route::get('store', [StoreController::class, 'show'])
        ->middleware('can:ecommerce.store.configure')->name('store.show');
    Route::put('store', [StoreController::class, 'update'])
        ->middleware('can.write:ecommerce.store.configure')->name('store.update');

    Route::get('articles/{article}/store-settings', [ArticleStoreSettingController::class, 'show'])
        ->middleware('can:ecommerce.store.configure')->name('store.article-settings.show');
    Route::put('articles/{article}/store-settings', [ArticleStoreSettingController::class, 'update'])
        ->middleware('can.write:ecommerce.store.configure')->name('store.article-settings.update');
});

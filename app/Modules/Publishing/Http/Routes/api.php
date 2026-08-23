<?php

declare(strict_types=1);

use App\Modules\Publishing\Http\Controllers\ArticlePublicationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Publicación de artículos — /api/v1 (Iteración 8, Tanda A)
|--------------------------------------------------------------------------
|
| La capa de publicación compartida por Menús y Tienda. Un solo permiso `publishing.articles.manage` para las dos
| superficies, de modo que un negocio con sólo uno de los módulos activables pueda editarla igual. NO va gateada por
| `module:` porque no es activable: está siempre disponible.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('articles/{article}/publication', [ArticlePublicationController::class, 'show'])
        ->middleware('can:publishing.articles.manage')->name('publishing.publication.show');

    Route::put('articles/{article}/publication', [ArticlePublicationController::class, 'update'])
        ->middleware('can.write:publishing.articles.manage')->name('publishing.publication.update');

    Route::post('articles/{article}/publication/images', [ArticlePublicationController::class, 'uploadImage'])
        ->middleware('can.write:publishing.articles.manage')->name('publishing.publication.images.store');

    Route::delete('publication-images/{articleImage}', [ArticlePublicationController::class, 'destroyImage'])
        ->middleware('can.write:publishing.articles.manage')->name('publishing.publication.images.destroy');
});

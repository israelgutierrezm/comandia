<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Http\Controllers\CartController;
use App\Modules\Ecommerce\Http\Controllers\PublicStoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tienda pública — sin autenticación (Iteración 8, Tanda B)
|--------------------------------------------------------------------------
|
| `/t/{slug}` sirve la tienda al cliente. Sin sesión de negocio: el slug —único globalmente— resuelve el tenant (lo hace
| el trait `ResolvesPublicStore`, que además verifica el módulo y que la tienda esté activa). El carrito vive en la sesión
| del cliente que trae el grupo `public`; sus mutaciones exigen el token CSRF que el shell incrusta.
|
*/

Route::get('/t/{slug}', [PublicStoreController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')->name('public.store');

Route::get('/t/{slug}/catalog', [PublicStoreController::class, 'catalog'])
    ->where('slug', '[a-z0-9-]+')->name('public.store.catalog');

Route::get('/t/{slug}/cart', [CartController::class, 'index'])
    ->where('slug', '[a-z0-9-]+')->name('public.store.cart.index');
Route::post('/t/{slug}/cart', [CartController::class, 'store'])
    ->where('slug', '[a-z0-9-]+')->name('public.store.cart.store');
Route::patch('/t/{slug}/cart', [CartController::class, 'update'])
    ->where('slug', '[a-z0-9-]+')->name('public.store.cart.update');
Route::delete('/t/{slug}/cart/{article}', [CartController::class, 'destroy'])
    ->where('slug', '[a-z0-9-]+')->where('article', '[0-9A-HJKMNP-TV-Z]{26}')->name('public.store.cart.destroy');

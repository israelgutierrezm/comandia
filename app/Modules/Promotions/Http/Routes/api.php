<?php

declare(strict_types=1);

use App\Modules\Promotions\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Promociones — /api/v1
|--------------------------------------------------------------------------
|
| Sólo la administración de DEFINICIONES. La aplicación de promociones no tiene endpoint propio: el POS la consume por el
| probe `PromotionResolver` del kernel al recalcular y cobrar (D310).
|
| Ver y administrar son dos permisos distintos (§6.3: la promoción tiene «permiso»). Los cupones —`promotions.coupons.manage`—
| no tienen ruta: son de e-commerce y llegan en la Iteración 8 (D314).
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('promotions', [PromotionController::class, 'index'])
        ->middleware('can:promotions.promotions.view')->name('promotions.index');

    Route::get('promotions/{promotion}', [PromotionController::class, 'show'])
        ->middleware('can:promotions.promotions.view')->name('promotions.show');

    Route::post('promotions', [PromotionController::class, 'store'])
        ->middleware('can.write:promotions.promotions.manage')->name('promotions.store');

    Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])
        ->middleware('can.write:promotions.promotions.manage')->name('promotions.update');
});

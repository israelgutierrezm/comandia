<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Http\Controllers\ArticleStoreSettingController;
use App\Modules\Ecommerce\Http\Controllers\CouponController;
use App\Modules\Ecommerce\Http\Controllers\OrderTrayController;
use App\Modules\Ecommerce\Http\Controllers\PaymentGatewaySettingController;
use App\Modules\Ecommerce\Http\Controllers\ShippingZoneController;
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

    // ---- Zonas de envío (Tanda C parte 2) ----
    Route::get('shipping-zones', [ShippingZoneController::class, 'index'])
        ->middleware('can:ecommerce.shipping_zones.manage')->name('shipping-zones.index');
    Route::post('shipping-zones', [ShippingZoneController::class, 'store'])
        ->middleware('can.write:ecommerce.shipping_zones.manage')->name('shipping-zones.store');
    Route::put('shipping-zones/{shippingZone}', [ShippingZoneController::class, 'update'])
        ->middleware('can.write:ecommerce.shipping_zones.manage')->name('shipping-zones.update');
    Route::delete('shipping-zones/{shippingZone}', [ShippingZoneController::class, 'destroy'])
        ->middleware('can.write:ecommerce.shipping_zones.manage')->name('shipping-zones.destroy');

    // ---- Pasarela de pago (Tanda C parte 3): el secreto financiero del negocio ----
    Route::get('payment-gateway', [PaymentGatewaySettingController::class, 'show'])
        ->middleware('can:ecommerce.gateways.configure')->name('payment-gateway.show');
    Route::put('payment-gateway', [PaymentGatewaySettingController::class, 'update'])
        ->middleware('can.write:ecommerce.gateways.configure')->name('payment-gateway.update');

    // ---- Bandeja de aceptación de pedidos (Tanda D) ----
    Route::get('orders', [OrderTrayController::class, 'index'])
        ->middleware('can:ecommerce.orders.view')->name('orders.index');
    Route::post('orders/{order}/accept', [OrderTrayController::class, 'accept'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.accept');
    Route::post('orders/{order}/reject', [OrderTrayController::class, 'reject'])
        ->middleware('can.write:ecommerce.orders.reject')->name('orders.reject');
    Route::post('orders/{order}/ready', [OrderTrayController::class, 'ready'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.ready');
    Route::post('orders/{order}/complete', [OrderTrayController::class, 'complete'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.complete');

    // ---- Cupones de la tienda (Tanda D, D3) ----
    Route::get('coupons', [CouponController::class, 'index'])
        ->middleware('can:ecommerce.coupons.manage')->name('coupons.index');
    Route::post('coupons', [CouponController::class, 'store'])
        ->middleware('can.write:ecommerce.coupons.manage')->name('coupons.store');
    Route::put('coupons/{coupon}', [CouponController::class, 'update'])
        ->middleware('can.write:ecommerce.coupons.manage')->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])
        ->middleware('can.write:ecommerce.coupons.manage')->name('coupons.destroy');
});

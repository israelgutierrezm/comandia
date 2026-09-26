<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Http\Controllers\ArticleStoreSettingController;
use App\Modules\Ecommerce\Http\Controllers\CouponController;
use App\Modules\Ecommerce\Http\Controllers\DeliveryChannelController;
use App\Modules\Ecommerce\Http\Controllers\MarketplaceMenuMapController;
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

    // ---- Canales de marketplace (ADR-015): encender/apagar y configurar DiDi/Uber/Rappi por sucursal ----
    // Es configuración de la tienda: reusa `ecommerce.store.configure`.
    Route::get('delivery-channels', [DeliveryChannelController::class, 'index'])
        ->middleware('can:ecommerce.store.configure')->name('delivery-channels.index');
    Route::put('delivery-channels', [DeliveryChannelController::class, 'upsert'])
        ->middleware('can.write:ecommerce.store.configure')->name('delivery-channels.upsert');

    // Mapeo del menú de cada marketplace (ADR-015, Fase 2): ítem externo → artículo, por canal. Sin él la
    // ingesta rechaza el pedido. Mismo permiso: es configuración de la tienda.
    Route::get('marketplace-menu-maps', [MarketplaceMenuMapController::class, 'index'])
        ->middleware('can:ecommerce.store.configure')->name('marketplace-menu-maps.index');
    Route::post('marketplace-menu-maps', [MarketplaceMenuMapController::class, 'store'])
        ->middleware('can.write:ecommerce.store.configure')->name('marketplace-menu-maps.store');
    Route::delete('marketplace-menu-maps/{marketplaceMenuMap}', [MarketplaceMenuMapController::class, 'destroy'])
        ->middleware('can.write:ecommerce.store.configure')->name('marketplace-menu-maps.destroy');

    // ---- Bandeja de aceptación de pedidos (Tanda D) ----
    Route::get('orders', [OrderTrayController::class, 'index'])
        ->middleware('can:ecommerce.orders.view')->name('orders.index');
    Route::post('orders/{order}/accept', [OrderTrayController::class, 'accept'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.accept');
    Route::post('orders/{order}/reject', [OrderTrayController::class, 'reject'])
        ->middleware('can.write:ecommerce.orders.reject')->name('orders.reject');
    Route::post('orders/{order}/ready', [OrderTrayController::class, 'ready'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.ready');
    // Camino de ENVÍO (modo dispatch, ADR-013): empacar → enviar. Entregar reusa `complete`. Mismo permiso
    // que atender la bandeja (`orders.accept`): es avanzar el fulfillment del pedido, no un permiso nuevo.
    Route::post('orders/{order}/pack', [OrderTrayController::class, 'pack'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.pack');
    Route::post('orders/{order}/ship', [OrderTrayController::class, 'ship'])
        ->middleware('can.write:ecommerce.orders.accept')->name('orders.ship');
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

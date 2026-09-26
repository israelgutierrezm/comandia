<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Tienda en línea (web) — Iteración 8, Tanda B
|--------------------------------------------------------------------------
|
| Pantalla de configuración de la tienda. La autorización real la aplican los endpoints de la API (`module:Ecommerce` +
| `ecommerce.store.configure`); el guard de navegación del shell oculta el enlace a quien no tiene el módulo o el permiso.
|
*/

Route::middleware(['auth'])->prefix('admin/tienda')->name('admin.store.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Index'))->name('index');
});

// Los canales de marketplace (ADR-015): encender/apagar DiDi/Uber/Rappi por sucursal y mapear su menú a los
// artículos. Pantalla propia porque son dos tareas con su lista cada una. Autorización real por
// `ecommerce.store.configure` en la API.
Route::middleware(['auth'])->prefix('admin/canales')->name('admin.channels.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Channels'))->name('index');
});

// La pasarela de pago es una pantalla aparte: exige `ecommerce.gateways.configure` (más restringido que la tienda), y el
// guard de navegación la oculta a quien no lo tiene. La autorización real la aplica la API.
Route::middleware(['auth'])->prefix('admin/pasarela')->name('admin.payment-gateway.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Gateway'))->name('index');
});

// La bandeja de aceptación de pedidos (Tanda D). La autorización real la aplican los endpoints (`ecommerce.orders.*`).
Route::middleware(['auth'])->prefix('admin/pedidos')->name('admin.store-orders.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Orders'))->name('index');
});

// Los cupones de la tienda (Tanda D, D3). Autorización real por `ecommerce.coupons.manage` en la API.
Route::middleware(['auth'])->prefix('admin/cupones')->name('admin.coupons.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Coupons'))->name('index');
});

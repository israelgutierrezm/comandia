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

// La pasarela de pago es una pantalla aparte: exige `ecommerce.gateways.configure` (más restringido que la tienda), y el
// guard de navegación la oculta a quien no lo tiene. La autorización real la aplica la API.
Route::middleware(['auth'])->prefix('admin/pasarela')->name('admin.payment-gateway.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Gateway'))->name('index');
});

// La bandeja de aceptación de pedidos (Tanda D). La autorización real la aplican los endpoints (`ecommerce.orders.*`).
Route::middleware(['auth'])->prefix('admin/pedidos')->name('admin.store-orders.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Store/Orders'))->name('index');
});

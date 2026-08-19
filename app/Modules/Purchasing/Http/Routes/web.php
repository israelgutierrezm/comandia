<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Shell de administración de compras (web)
|--------------------------------------------------------------------------
|
| Sólo entrega páginas; los datos vienen de `/api/v1` (D59).
|
| El detalle de la recepción recibe su ULID por parámetro porque es un documento con estados: hay que
| poder enlazarlo, volver a él y confirmarlo desde su propia dirección. El listado no basta.
|
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.purchasing.')->group(function (): void {
    Route::get('proveedores', fn () => Inertia::render('Admin/Purchasing/Suppliers/Index'))->name('suppliers');

    Route::get('recepciones', fn () => Inertia::render('Admin/Purchasing/Receipts/Index'))->name('receipts');

    Route::get('recepciones/{recepcion}', fn (string $recepcion) => Inertia::render(
        'Admin/Purchasing/Receipts/Show',
        ['receiptUlid' => $recepcion],
    ))->where('recepcion', '[0-9A-HJKMNP-TV-Z]{26}')->name('receipts.show');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Clientes (web)
|--------------------------------------------------------------------------
|
| La lista y la ficha del expediente. La ficha recibe el ULID del cliente por parámetro —como el kardex de un artículo—:
| no es un dato de dominio, es a qué cliente se refiere la pantalla. La autorización la aplica cada endpoint (D59).
|
*/

Route::middleware(['auth'])->prefix('admin/clientes')->name('admin.customers.')->group(function (): void {
    Route::get('/', fn () => Inertia::render('Admin/Customers/Index'))->name('index');

    Route::get('{cliente}', fn (string $cliente) => Inertia::render(
        'Admin/Customers/Show',
        ['customerUlid' => $cliente],
    ))->where('cliente', '[0-9A-HJKMNP-TV-Z]{26}')->name('show');
});

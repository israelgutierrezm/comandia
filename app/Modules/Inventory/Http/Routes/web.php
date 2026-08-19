<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Shell de administración de inventarios (web)
|--------------------------------------------------------------------------
|
| Igual que el resto del shell: estas rutas sólo entregan la página. Los datos vienen de `/api/v1`
| (D59) y la autorización la aplica cada endpoint — no estas rutas. Un usuario sin permiso ve la
| pantalla y recibe 403 al pedir los datos, y la navegación no le ofrece el enlace: los tres niveles
| de §4.2.
|
| ## El kardex recibe el artículo por parámetro
|
| Como el detalle del artículo en el catálogo: no es un dato de dominio, es la respuesta a «¿de qué
| artículo estamos hablando?», que la página no puede adivinar. Leerlo de `window.location` sería lo
| mismo con más pasos y sin validación de forma.
|
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.inventory.')->group(function (): void {
    Route::get('existencias', fn () => Inertia::render('Admin/Inventory/Stock/Index'))->name('stock');

    Route::get('existencias/{articulo}/kardex', fn (string $articulo) => Inertia::render(
        'Admin/Inventory/Stock/Kardex',
        ['articleUlid' => $articulo],
    ))->where('articulo', '[0-9A-HJKMNP-TV-Z]{26}')->name('kardex');

    Route::get('mermas', fn () => Inertia::render('Admin/Inventory/Waste/Index'))->name('waste');

    Route::get('conteos', fn () => Inertia::render('Admin/Inventory/Counts/Index'))->name('counts');

    // El conteo recibe su ULID: es un documento con estados y hay que poder volver a él para capturar y cerrar.
    Route::get('conteos/{conteo}', fn (string $conteo) => Inertia::render(
        'Admin/Inventory/Counts/Show',
        ['countUlid' => $conteo],
    ))->where('conteo', '[0-9A-HJKMNP-TV-Z]{26}')->name('counts.show');

    Route::get('transferencias', fn () => Inertia::render('Admin/Inventory/Transfers/Index'))->name('transfers');

    Route::get('transferencias/{transferencia}', fn (string $transferencia) => Inertia::render(
        'Admin/Inventory/Transfers/Show',
        ['transferUlid' => $transferencia],
    ))->where('transferencia', '[0-9A-HJKMNP-TV-Z]{26}')->name('transfers.show');

    Route::get('produccion', fn () => Inertia::render('Admin/Inventory/Production/Index'))->name('production');

    Route::get('produccion/{orden}', fn (string $orden) => Inertia::render(
        'Admin/Inventory/Production/Show',
        ['orderUlid' => $orden],
    ))->where('orden', '[0-9A-HJKMNP-TV-Z]{26}')->name('production.show');
});

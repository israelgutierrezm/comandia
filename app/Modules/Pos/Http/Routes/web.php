<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Shell del punto de venta (web)
|--------------------------------------------------------------------------
|
| Igual que el resto del shell: estas rutas sólo entregan la página. Los datos vienen de `/api/v1` (D59) y la
| autorización la aplica cada endpoint — no estas rutas. Un cajero sin permiso de ver el corte entra a la pantalla de
| caja y el bloque del corte simplemente no aparece: es el precorte ciego funcionando, no un error.
|
| ## La cuenta recibe su ULID por parámetro
|
| Como el kardex de un artículo: no es un dato de dominio, es la respuesta a «¿de qué cuenta estamos hablando?», que la
| página no puede adivinar. Leerlo de `window.location` sería lo mismo con más pasos y sin validación de forma.
|
*/

Route::middleware(['auth'])->prefix('admin/pos')->name('admin.pos.')->group(function (): void {
    Route::get('caja', fn () => Inertia::render('Admin/Pos/CashSession'))->name('cash-session');

    // El piso de venta: el salón dibujado con lo que está pasando encima. Es la pantalla que se mira de reojo, así que
    // se refresca sola — por socket si lo hay, y por sondeo si no.
    Route::get('piso', fn () => Inertia::render('Admin/Pos/Floor'))->name('floor');

    // La pantalla de la cocina. Es un espejo del papel, no su sustituto: el trabajo de impresión se sigue generando.
    Route::get('comandas', fn () => Inertia::render('Admin/Pos/Commands'))->name('commands');

    Route::get('cuentas', fn () => Inertia::render('Admin/Pos/Accounts'))->name('accounts');

    Route::get('cuentas/{cuenta}', fn (string $cuenta) => Inertia::render(
        'Admin/Pos/Account',
        ['accountUlid' => $cuenta],
    ))->where('cuenta', '[0-9A-HJKMNP-TV-Z]{26}')->name('account');
});

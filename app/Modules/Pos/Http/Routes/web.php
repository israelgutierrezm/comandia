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

    Route::get('cuentas', fn () => Inertia::render('Admin/Pos/Accounts'))->name('accounts');

    Route::get('cuentas/{cuenta}', fn (string $cuenta) => Inertia::render(
        'Admin/Pos/Account',
        ['accountUlid' => $cuenta],
    ))->where('cuenta', '[0-9A-HJKMNP-TV-Z]{26}')->name('account');
});

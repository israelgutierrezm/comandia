<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\PinAuthorizationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Identity — /api/v1
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'throttle:pin'])->group(function (): void {
    /*
     * Autorización por PIN (ADR-008).
     *
     * `throttle:pin` es parte de la decisión, no un extra: un endpoint que compara PIN de
     * cuatro dígitos sin límite de intentos es un espacio de 10,000 combinaciones abierto a
     * la fuerza bruta. El bloqueo por membresía cubre el ataque dirigido a una persona; el
     * límite por terminal e IP cubre el barrido sobre muchas.
     */
    Route::post('authorizations', PinAuthorizationController::class)->name('authorizations.store');
});

<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Controllers\ContextController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del shared kernel — /api/v1
|--------------------------------------------------------------------------
|
| Registradas por App\Providers\ModuleServiceProvider con el prefijo `api/v1` y el
| middleware `api`.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    // El contexto operativo. Sin permiso adicional a propósito: preguntar quién eres y
    // qué puedes hacer no requiere permiso —es la respuesta a esa pregunta la que los
    // contiene— y exigirlo crearía un huevo y la gallina en el arranque del shell.
    Route::get('context', ContextController::class)->name('context.show');
});

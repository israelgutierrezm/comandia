<?php

declare(strict_types=1);

use App\Modules\DigitalMenus\Http\Controllers\PublicMenuController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Menú público por QR — sin autenticación (Iteración 8, Tanda A)
|--------------------------------------------------------------------------
|
| `/m/{slug}` sirve el menú de una sucursal al teléfono del cliente. No hay sesión ni contexto de tenant: el slug —único
| globalmente— resuelve el negocio. El controlador fija el contexto y verifica que el módulo esté activo (si no, 404). El
| gate `module:` NO se usa aquí porque corre antes de que exista contexto; la verificación va dentro, ya con el tenant
| resuelto por el slug.
|
*/

Route::get('/m/{slug}', [PublicMenuController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('public.menu');

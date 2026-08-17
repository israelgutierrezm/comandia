<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — raíz
|--------------------------------------------------------------------------
|
| Registrado con prefijo `api/v1` en bootstrap/app.php. Este archivo NO contiene
| endpoints de dominio: cada módulo publica los suyos en
| app/Modules/{Modulo}/Http/Routes/api.php.
|
| El único endpoint de aquí es el de identificación de la API, útil para que la
| app Flutter y los agentes de impresión verifiquen versión antes de operar.
|
*/

Route::get('/', fn (): JsonResponse => new JsonResponse([
    'data' => [
        'name' => config('app.name'),
        'api_version' => 'v1',
    ],
]))->name('root');

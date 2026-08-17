<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web (shell de la aplicación)
|--------------------------------------------------------------------------
|
| Este archivo sólo contiene el shell. Las rutas de cada módulo viven en
| app/Modules/{Modulo}/Http/Routes/web.php y las registra
| App\Providers\ModuleServiceProvider.
|
*/

/**
 * La raíz no tiene pantalla propia: reparte según haya sesión o no.
 *
 * Antes renderizaba la página `Welcome` de la Fase 0, que se eliminó al construir la UI de
 * administración **sin actualizar esta ruta**. El test que la cubría usaba `withoutVite()`
 * —correcto, para no depender del manifest compilado— y por eso siguió en verde sin montar Vue
 * jamás: en el navegador, la primera pantalla del proyecto era una excepción de JavaScript y un
 * `<div>` vacío. Lo encontró el navegador, no la suite.
 */
Route::get('/', fn () => Auth::check()
    ? redirect()->route('admin.dashboard')
    : redirect()->route('login'))->name('home');

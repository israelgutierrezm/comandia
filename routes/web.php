<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

Route::get('/', fn () => Inertia::render('Welcome', [
    'laravelVersion' => Application::VERSION,
    'phpVersion' => PHP_VERSION,
]))->name('home');

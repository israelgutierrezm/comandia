<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\BusinessController;
use App\Modules\Platform\Http\Controllers\PlatformDashboardController;
use App\Modules\Platform\Http\Controllers\PlatformLoginController;
use App\Modules\Platform\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Super administración de la plataforma (web)
|--------------------------------------------------------------------------
|
| Grupo de middleware `platform` (ver bootstrap/app.php): sesión con cookie propia, sin contexto de tenant. El acceso es
| público (para poder iniciar sesión); todo lo demás va detrás de `EnsureSuperAdmin`.
|
*/

Route::prefix('plataforma')->name('platform.')->group(function (): void {
    Route::get('acceso', [PlatformLoginController::class, 'show'])->name('login');
    Route::post('acceso', [PlatformLoginController::class, 'store'])->name('login.store');

    Route::middleware(EnsureSuperAdmin::class)->group(function (): void {
        Route::post('salir', [PlatformLoginController::class, 'destroy'])->name('logout');

        Route::get('/', [PlatformDashboardController::class, 'show'])->name('dashboard');

        Route::get('negocios', [BusinessController::class, 'index'])->name('businesses.index');
        Route::get('negocios/nuevo', [BusinessController::class, 'create'])->name('businesses.create');
        Route::post('negocios', [BusinessController::class, 'store'])->name('businesses.store');
        Route::get('negocios/{tenant}', [BusinessController::class, 'show'])->name('businesses.show');
        Route::post('negocios/{tenant}/estado', [BusinessController::class, 'updateStatus'])->name('businesses.status');
    });
});

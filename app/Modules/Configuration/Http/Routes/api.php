<?php

declare(strict_types=1);

use App\Modules\Configuration\Http\Controllers\BranchSettingController;
use App\Modules\Configuration\Http\Controllers\MailSettingController;
use App\Modules\Configuration\Http\Controllers\PreferencesThemeController;
use App\Modules\Configuration\Http\Controllers\TenantSettingController;
use App\Modules\Configuration\Http\Controllers\ThemeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Configuration — /api/v1
|--------------------------------------------------------------------------
|
| La llave viaja en la URL y no en el cuerpo porque es la identidad del recurso: `settings/{key}`
| se lee, se escribe y se restaura como cualquier otro recurso, y eso hace que el cliente pueda
| cachearlo e invalidarlo por URL.
|
| `{key}` admite puntos —`pos.blind_precount`— así que lleva su propia restricción de patrón: sin
| ella, Laravel corta el parámetro en el primer punto.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Nivel tenant ----
    Route::get('settings', [TenantSettingController::class, 'index'])
        ->middleware('can:configuration.tenant.view')->name('settings.index');

    Route::put('settings/{key}', [TenantSettingController::class, 'update'])
        ->where('key', '[a-z0-9_.]+')
        ->middleware('can.write:configuration.tenant.update')->name('settings.update');

    Route::delete('settings/{key}', [TenantSettingController::class, 'destroy'])
        ->where('key', '[a-z0-9_.]+')
        ->middleware('can.write:configuration.tenant.update')->name('settings.destroy');

    // ---- Apariencia: temas (estilo Acadion) ----
    //
    // Elegir y personalizar el tema PROPIO no pide permiso: es preferencia de la persona. Fijar el tema por OMISIÓN del
    // negocio sí, con el permiso de configuración del tenant.
    Route::put('preferences/theme', [PreferencesThemeController::class, 'setTheme'])
        ->name('preferences.theme.set');
    Route::put('preferences/theme/color', [PreferencesThemeController::class, 'setColor'])
        ->name('preferences.theme.color');
    Route::delete('preferences/theme/overrides', [PreferencesThemeController::class, 'clearOverrides'])
        ->name('preferences.theme.overrides.clear');

    Route::post('themes/{theme}/default', [ThemeController::class, 'setDefault'])
        ->middleware('can.write:configuration.tenant.update')->name('themes.default');

    // ---- Correo del negocio (Tanda D1) ----
    Route::get('mail-settings', [MailSettingController::class, 'show'])
        ->middleware('can:configuration.tenant.view')->name('mail-settings.show');
    Route::put('mail-settings', [MailSettingController::class, 'update'])
        ->middleware('can.write:configuration.tenant.update')->name('mail-settings.update');
    Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])
        ->middleware('can.write:configuration.tenant.update')->name('mail-settings.test');

    // ---- Nivel sucursal ----
    Route::get('branches/{branch}/settings', [BranchSettingController::class, 'index'])
        ->middleware('can:configuration.branch.view')->name('branches.settings.index');

    Route::put('branches/{branch}/settings/{key}', [BranchSettingController::class, 'update'])
        ->where('key', '[a-z0-9_.]+')
        ->middleware('can.write:configuration.branch.update')->name('branches.settings.update');

    Route::delete('branches/{branch}/settings/{key}', [BranchSettingController::class, 'destroy'])
        ->where('key', '[a-z0-9_.]+')
        ->middleware('can.write:configuration.branch.update')->name('branches.settings.destroy');
});

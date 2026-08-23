<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\TenantModuleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenancy — /api/v1 (Iteración 8, Tanda A)
|--------------------------------------------------------------------------
|
| Los módulos activables del negocio. Es la primera superficie del módulo `Tenancy` (hasta ahora no tenía controlador).
| Ver con `tenancy.modules.view`; cambiar con `tenancy.modules.manage` —ambos del propietario, no del gerente: activar un
| módulo es comercial (D4)—.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('modules', [TenantModuleController::class, 'index'])
        ->middleware('can:tenancy.modules.view')->name('modules.index');

    Route::put('modules/{module}', [TenantModuleController::class, 'update'])
        ->middleware('can.write:tenancy.modules.manage')->name('modules.update');
});

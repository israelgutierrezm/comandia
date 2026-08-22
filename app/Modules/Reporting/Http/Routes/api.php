<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Motor de reportes — /api/v1 (ADR-006, ADR-007)
|--------------------------------------------------------------------------
|
| Un solo endpoint genérico. El permiso NO va en el middleware porque depende del reporte pedido: lo verifica el motor
| contra la definición (ADR-006 regla 3). Por eso estas rutas están declaradas como excepción en `RoutePermissionTest`,
| igual que `broadcasting/auth`, cuyo permiso depende del canal.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}/definition', [ReportController::class, 'definition'])->name('reports.definition');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
});

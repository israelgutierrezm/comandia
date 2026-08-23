<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\ExportController;
use App\Modules\Reporting\Http\Controllers\GoalStatusController;
use App\Modules\Reporting\Http\Controllers\ReportController;
use App\Modules\Reporting\Http\Controllers\ReportGoalController;
use App\Modules\Reporting\Http\Controllers\SavedReportViewController;
use App\Modules\Reporting\Http\Controllers\ScheduledReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Motor de reportes — /api/v1 (ADR-006, ADR-009)
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

    // ---- Exportación (Tanda B) ----
    // Crear un export exige el permiso del reporte (in-code, como el motor). Listar/consultar/descargar están acotados
    // al AUTOR del export. Sin permiso fijo en la ruta: excepción declarada en RoutePermissionTest.
    Route::post('reports/{report}/exports', [ExportController::class, 'store'])->name('reports.exports.store');
    Route::get('report-exports', [ExportController::class, 'index'])->name('report-exports.index');
    Route::get('report-exports/{reportExport}', [ExportController::class, 'show'])->name('report-exports.show');
    Route::get('report-exports/{reportExport}/download', [ExportController::class, 'download'])->name('report-exports.download');

    // ---- Vistas guardadas (Tanda B) ----
    // Tienen permiso fijo (`reporting.saved_views.manage`) y además se acotan al autor en el controlador.
    Route::get('reports/{report}/views', [SavedReportViewController::class, 'index'])
        ->middleware('can:reporting.saved_views.manage')->name('reports.views.index');
    Route::post('reports/{report}/views', [SavedReportViewController::class, 'store'])
        ->middleware('can.write:reporting.saved_views.manage')->name('reports.views.store');
    Route::delete('report-views/{savedReportView}', [SavedReportViewController::class, 'destroy'])
        ->middleware('can.write:reporting.saved_views.manage')->name('report-views.destroy');

    // ---- Metas y semáforo (Tanda C) ----
    // Administrar metas tiene permiso fijo. El estado del semáforo corre el reporte, así que su permiso es el del reporte
    // (in-code): excepción declarada en RoutePermissionTest.
    Route::get('report-goals', [ReportGoalController::class, 'index'])
        ->middleware('can:dashboards.goals.manage')->name('report-goals.index');
    Route::post('report-goals', [ReportGoalController::class, 'store'])
        ->middleware('can.write:dashboards.goals.manage')->name('report-goals.store');
    Route::delete('report-goals/{reportGoal}', [ReportGoalController::class, 'destroy'])
        ->middleware('can.write:dashboards.goals.manage')->name('report-goals.destroy');

    Route::get('reports/{report}/goal-status', GoalStatusController::class)->name('reports.goal-status');

    // ---- Reportes programados (Tanda D3) ----
    // Permiso fijo para programar; además el controlador exige el permiso del reporte concreto y acota todo al autor. El
    // envío real ocurre en un job con el alcance del autor. Correr «ahora» es para probar sin esperar al scheduler.
    Route::get('scheduled-reports', [ScheduledReportController::class, 'index'])
        ->middleware('can:reporting.schedules.manage')->name('scheduled-reports.index');
    Route::post('scheduled-reports', [ScheduledReportController::class, 'store'])
        ->middleware('can.write:reporting.schedules.manage')->name('scheduled-reports.store');
    Route::post('scheduled-reports/{scheduledReport}/run', [ScheduledReportController::class, 'runNow'])
        ->middleware('can.write:reporting.schedules.manage')->name('scheduled-reports.run');
    Route::delete('scheduled-reports/{scheduledReport}', [ScheduledReportController::class, 'destroy'])
        ->middleware('can.write:reporting.schedules.manage')->name('scheduled-reports.destroy');

    // ---- Tableros (Tanda C) ----
    // Ver con dashboards.dashboards.view; construir con .manage; publicar con .publish (in-code al publicar). Editar/borrar
    // acotado al autor. Los widgets heredan el permiso de su reporte al pintarse (ADR-006).
    Route::get('dashboards', [DashboardController::class, 'index'])
        ->middleware('can:dashboards.dashboards.view')->name('dashboards.index');
    Route::post('dashboards', [DashboardController::class, 'store'])
        ->middleware('can.write:dashboards.dashboards.manage')->name('dashboards.store');
    Route::get('dashboards/{dashboard}', [DashboardController::class, 'show'])
        ->middleware('can:dashboards.dashboards.view')->name('dashboards.show');
    Route::patch('dashboards/{dashboard}', [DashboardController::class, 'update'])
        ->middleware('can.write:dashboards.dashboards.manage')->name('dashboards.update');
    Route::delete('dashboards/{dashboard}', [DashboardController::class, 'destroy'])
        ->middleware('can.write:dashboards.dashboards.manage')->name('dashboards.destroy');
    Route::post('dashboards/{dashboard}/widgets', [DashboardController::class, 'addWidget'])
        ->middleware('can.write:dashboards.dashboards.manage')->name('dashboards.widgets.store');
    Route::delete('dashboard-widgets/{dashboardWidget}', [DashboardController::class, 'removeWidget'])
        ->middleware('can.write:dashboards.dashboards.manage')->name('dashboard-widgets.destroy');
});

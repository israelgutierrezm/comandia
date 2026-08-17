<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditEntryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Audit — /api/v1
|--------------------------------------------------------------------------
|
| Sólo lectura, y la ausencia de escritura es deliberada: la bitácora es append-only
| (ARQUITECTURA_MAESTRA §7) y sólo escriben los servicios de dominio a través de `AuditLogger`.
| Un endpoint de escritura permitiría fabricar evidencia.
|
| Tampoco hay borrado. El archivado a los 12 meses (D47) es una tarea del sistema, no una acción
| del tenant: si el tenant pudiera borrar su bitácora, no serviría para investigarlo.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('audit-entries', [AuditEntryController::class, 'index'])
        ->middleware('can:audit.entries.view')->name('audit-entries.index');

    Route::get('audit-entries/{auditEntry}', [AuditEntryController::class, 'show'])
        ->middleware('can:audit.entries.view')->name('audit-entries.show');
});

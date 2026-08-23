<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notificaciones — /api/v1 (Tanda D2)
|--------------------------------------------------------------------------
|
| Sin permiso fijo: los avisos son PERSONALES (dirigidos a la membresía o al rol activo de quien mira), como «mi
| contexto». La acotación al destinatario la hace el controlador. Declaradas como excepción en RoutePermissionTest.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Platform\Mail\PlatformPasswordChangedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Cambiar la contraseña de un super administrador (D382), desde «Mi cuenta» o desde la consola.
 *
 * Cambiar la contraseña tiene que sacar de verdad a quien tenga otra sesión abierta:
 * - las sesiones del panel caen en su siguiente petición, porque `EnsureSuperAdmin` compara la huella de la contraseña;
 * - se rota `remember_token`, así que ninguna cookie de «Mantener la sesión abierta» emitida antes vuelve a entrar.
 *
 * Cada cambio deja constancia en el canal `platform` y le avisa al admin por correo: es lo único que le avisa si quien lo
 * cambió no era él.
 */
final class PlatformPasswords
{
    public function __construct(private readonly PlatformAccessLog $log) {}

    /**
     * @param  'panel'|'consola'  $via
     */
    public function change(PlatformAdmin $admin, string $newPassword, string $via, ?Request $request = null): void
    {
        // El cast `hashed` cifra la contraseña al asignarla.
        $admin->forceFill(['password' => $newPassword]);
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        $this->log->record(PlatformAccessLog::PASSWORD_CHANGED, $admin, $via, $request);

        Mail::to($admin->email)->send(new PlatformPasswordChangedMail(
            adminName: (string) $admin->name,
            changedAt: now('America/Mexico_City')->format('d/m/Y H:i'),
            via: $via,
            ip: $request?->ip(),
        ));
    }
}

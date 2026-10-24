<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\PlatformPasswords;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Console\Command;

/**
 * Cambiar la contraseña de un super administrador por consola. SÓLO cambia (D382).
 *
 * Es la recuperación de la plataforma: no hay «¿Olvidaste tu contraseña?» público, y quien opera el servidor (D57) es un
 * factor mucho más fuerte que un buzón. Si el correo no existe, falla sin crear nada; no toca el nombre.
 *
 * Como desde «Mi cuenta»: rota `remember_token`, cierra las sesiones abiertas, deja constancia y le avisa al admin.
 */
final class ChangePlatformAdminPasswordCommand extends Command
{
    use AsksForPlatformPassword;

    protected $signature = 'comandia:platform-admin:password
        {email : Correo del super administrador}';

    protected $description = 'Cambia la contraseña de un super administrador de la plataforma y cierra sus sesiones.';

    public function handle(PlatformPasswords $passwords): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $admin = PlatformAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->components->error('No hay ningún super administrador con ese correo. Para darlo de alta usa: php artisan comandia:platform-admin');

            return self::FAILURE;
        }

        $password = $this->askForNewPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $passwords->change($admin, $password, via: 'consola');

        $this->components->info(sprintf(
            'Contraseña de «%s» actualizada. Sus sesiones abiertas se cerraron y se le avisó por correo.',
            $admin->email,
        ));

        return self::SUCCESS;
    }
}

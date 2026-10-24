<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\PlatformAccessLog;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Console\Command;

/**
 * Alta de un super administrador de la plataforma. SÓLO crea (D382).
 *
 * Es el bootstrap del sistema: el PRIMER super admin no puede crearse desde el panel —que ya exige ser super admin—,
 * así que se crea por consola. Antes también cambiaba contraseñas con `updateOrCreate`, y un correo mal escrito creaba
 * otro super admin; ahora un correo que ya existe falla y remite a `comandia:platform-admin:password`.
 *
 * La contraseña se pide oculta, dos veces: nunca por opción, que la dejaría en el historial de la terminal y en la lista
 * de procesos.
 */
final class CreatePlatformAdminCommand extends Command
{
    use AsksForPlatformPassword;

    protected $signature = 'comandia:platform-admin
        {email : Correo del super administrador}
        {--name= : Nombre a mostrar (por omisión, la parte antes de la @)}';

    protected $description = 'Da de alta un super administrador de la plataforma.';

    public function handle(PlatformAccessLog $log): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('El correo no tiene un formato válido.');

            return self::FAILURE;
        }

        if (PlatformAdmin::query()->where('email', $email)->exists()) {
            $this->components->error(sprintf(
                'Ese correo ya es super administrador. Para cambiar su contraseña usa: php artisan comandia:platform-admin:password %s',
                $email,
            ));

            return self::FAILURE;
        }

        $password = $this->askForNewPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        // El cast `hashed` del modelo cifra la contraseña al asignarla.
        $admin = PlatformAdmin::query()->create([
            'email' => $email,
            'name' => (string) ($this->option('name') ?: (strstr($email, '@', true) ?: 'Super admin')),
            'password' => $password,
        ]);

        $log->record(PlatformAccessLog::ADMIN_CREATED, $admin, via: 'consola');

        $this->components->info(sprintf('Super administrador «%s» creado (%s).', $admin->name, $admin->email));

        return self::SUCCESS;
    }
}

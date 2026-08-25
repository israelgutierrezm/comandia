<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Console\Command;

/**
 * Alta (o cambio de contraseña) de un super administrador de la plataforma.
 *
 * Es el bootstrap del sistema: el PRIMER super admin no puede crearse desde el panel —que ya exige ser super admin—,
 * así que se crea por consola. La contraseña se pide de forma oculta si no se pasa por opción.
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'comandia:platform-admin
        {email : Correo del super administrador}
        {--name= : Nombre a mostrar (por omisión, la parte antes de la @)}
        {--password= : Contraseña; si se omite, se pide de forma oculta}';

    protected $description = 'Crea o actualiza un super administrador de la plataforma.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('El correo no tiene un formato válido.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: (strstr($email, '@', true) ?: 'Super admin'));
        $password = (string) ($this->option('password') ?: $this->secret('Contraseña'));

        if (mb_strlen($password) < 8) {
            $this->components->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        // El cast `hashed` del modelo cifra la contraseña al asignarla.
        $admin = PlatformAdmin::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        $this->components->info(sprintf('Super administrador «%s» listo (%s).', $admin->name, $admin->email));

        return self::SUCCESS;
    }
}

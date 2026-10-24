<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Http\Requests\ChangePasswordRequest;

/**
 * Pide la contraseña nueva de un super administrador: oculta, dos veces y con el mínimo del personal (D382).
 *
 * @mixin \Illuminate\Console\Command
 */
trait AsksForPlatformPassword
{
    private function askForNewPassword(): ?string
    {
        $password = (string) $this->secret('Contraseña');
        $confirmacion = (string) $this->secret('Repite la contraseña');

        if (mb_strlen($password) < ChangePasswordRequest::MIN_LENGTH) {
            $this->components->error(sprintf('La contraseña debe tener al menos %d caracteres.', ChangePasswordRequest::MIN_LENGTH));

            return null;
        }

        if (! hash_equals($password, $confirmacion)) {
            $this->components->error('Las dos contraseñas no coinciden.');

            return null;
        }

        return $password;
    }
}

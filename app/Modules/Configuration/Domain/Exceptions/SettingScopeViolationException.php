<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Exceptions;

use App\Modules\Configuration\Domain\Enums\SettingScope;
use RuntimeException;

/**
 * Se intentó sobrescribir una llave en un nivel que su definición no permite.
 *
 * El caso típico: alguien intenta poner `locale` por sucursal. Permitirlo en silencio
 * crearía una fila que la cascada sí leería, y entonces una sucursal hablaría otro
 * idioma que el resto del tenant por accidente.
 */
final class SettingScopeViolationException extends RuntimeException
{
    public static function make(string $key, SettingScope $attempted, SettingScope $maxScope): self
    {
        return new self(sprintf(
            'La llave «%s» no admite override a nivel %s: su nivel máximo es %s.',
            $key,
            $attempted->label(),
            $maxScope->label(),
        ));
    }
}

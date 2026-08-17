<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Exceptions;

use RuntimeException;

/**
 * El valor no corresponde al tipo o al conjunto declarado en el catálogo.
 */
final class InvalidSettingValueException extends RuntimeException
{
    public static function wrongType(string $key, string $expected, mixed $given): self
    {
        return new self(sprintf(
            'La llave «%s» espera un valor %s y recibió %s.',
            $key,
            $expected,
            get_debug_type($given),
        ));
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function notAllowed(string $key, string $given, array $allowed): self
    {
        return new self(sprintf(
            'El valor «%s» no es válido para la llave «%s». Valores permitidos: %s.',
            $given,
            $key,
            implode(', ', $allowed),
        ));
    }
}

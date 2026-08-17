<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Exceptions;

use RuntimeException;

/**
 * Se pidió o se intentó escribir una llave que no está en el catálogo.
 *
 * "Prohibido inventar llaves desde el cliente" (ARQUITECTURA_MAESTRA §5) tiene que
 * ser un error y no un `INSERT`: una llave inventada crearía una fila que nadie lee,
 * y el usuario creería haber configurado algo que el sistema ignora por completo.
 */
final class UnknownSettingKeyException extends RuntimeException
{
    public static function make(string $key): self
    {
        return new self(sprintf(
            'La llave de configuración «%s» no está en el catálogo. Las llaves se declaran en '
            .'código (SettingCatalog); prohibido inventarlas desde el cliente '
            .'(ARQUITECTURA_MAESTRA §5).',
            $key,
        ));
    }
}

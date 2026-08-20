<?php

declare(strict_types=1);

namespace App\Modules\Floor\Domain\Exceptions;

use DomainException;

/**
 * Se intentó dejar una mesa en un estado que el salón no admite.
 *
 * Se lanza desde el **modelo** y no sólo desde el Form Request: la unión de mesas se hace desde el POS, desde la
 * pantalla de piso y —cuando llegue— desde la app. Tres caminos, un solo sitio donde vive la regla.
 */
final class TableInvariantException extends DomainException
{
    public static function cannotJoinItself(string $code): self
    {
        return new self(sprintf(
            'La mesa %s no se puede unir a sí misma.',
            $code,
        ));
    }

    public static function cannotChainJoins(string $code, string $targetCode): self
    {
        return new self(sprintf(
            'La mesa %s no se puede unir a %s, porque %s ya está unida a otra. Una unión es plana: hay una mesa '
            .'principal y las demás cuelgan de ella. Con uniones en cadena, «¿de quién es esta cuenta?» tendría que '
            .'recorrer un árbol y al pagar habría que deshacer ramas.',
            $code,
            $targetCode,
            $targetCode,
        ));
    }

    public static function cannotJoinBusyTable(string $code): self
    {
        return new self(sprintf(
            'La mesa %s tiene servicio en curso: unirla movería su cuenta a otra mesa. Cierra o mueve su cuenta antes '
            .'de unirla.',
            $code,
        ));
    }
}

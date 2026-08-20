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
    /**
     * Se intentó sentar gente en una mesa que no está disponible.
     *
     * ## Por qué existe si `PosAccountException::tableNotAvailable` ya decía esto
     *
     * Porque dicen dos cosas distintas y responden con dos códigos distintos, a propósito. `Pos` comprueba antes de
     * abrir y responde **409**: desde el punto de vista de quien opera es un conflicto —«esa mesa ya tiene gente»— y la
     * pantalla ofrece abrir otra cuenta o elegir otra mesa.
     *
     * Ésta es la última línea: la comprobación de `TableOccupancy::occupy()`, que corre ya dentro de la transacción. Si
     * llega a lanzarse es porque hubo una **carrera** entre dos meseros sentando gente en la misma mesa, no un error de
     * quien pidió. No se llega aquí por el camino normal, y por eso el mensaje habla de la mesa y no de la cuenta.
     */
    public static function notAvailable(string $code): self
    {
        return new self(sprintf(
            'La mesa %s no está disponible: tiene servicio en curso, está unida a otra o espera limpieza.',
            $code,
        ));
    }

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

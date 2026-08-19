<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use DomainException;

/**
 * Se intentó cambiar algo que un método de pago del sistema no admite.
 *
 * Se lanza desde el **modelo** y no sólo desde el Form Request, a propósito: un Form Request protege el camino HTTP, y
 * estas reglas también tienen que valer para seeders, comandos de consola y cualquier servicio interno. Una regla que
 * sólo vive en la capa HTTP es una regla que la primera importación masiva se salta.
 *
 * ## Y una excepción propia en lugar de `LogicException`
 *
 * La primera versión lanzaba `LogicException` y el proveedor la traducía mirando el trace para saber si venía de este
 * módulo. No funcionó, y el motivo es instructivo: los invariantes de modelo se lanzan desde un **closure del
 * despachador de eventos de Eloquent**, así que `getTrace()[0]['class']` es el despachador y no el modelo. El resultado
 * era un 500 donde tocaba un 422.
 *
 * Con una clase propia el traductor la reconoce por su tipo, que es lo que el resto del proyecto ya hacía.
 */
final class PaymentMethodInvariantException extends DomainException
{
    public static function systemFieldIsFrozen(string $field, string $code): self
    {
        return new self(sprintf(
            'No se puede cambiar «%s» de un método de pago del sistema (%s). Los cuatro del sistema son la '
            .'referencia con la que los cortes y los reportes agrupan; si necesitas otro nombre o otro '
            .'comportamiento, da de alta un método propio.',
            $field,
            $code,
        ));
    }

    public static function systemCannotBeDeleted(string $code): self
    {
        return new self(sprintf(
            'Un método de pago del sistema no se borra (%s): se da de baja. Los pagos ya cobrados lo citan, y un '
            .'pago que no puede decir con qué se pagó no explica nada.',
            $code,
        ));
    }
}

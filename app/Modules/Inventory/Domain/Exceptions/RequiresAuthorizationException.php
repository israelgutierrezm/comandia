<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

/**
 * La operación pasa un umbral de monto del negocio y no traía autorización.
 *
 * Base común de las mermas (D170) y del cierre de conteos, y existe porque el **contrato HTTP tiene que ser uno
 * solo**: `409` con `type: authorization_required` y el permiso que hace falta. Si cada operación mapeara su
 * propia excepción, el cliente que abre el diálogo del PIN tendría que reconocer una respuesta distinta por
 * operación, y la tercera se le olvidaría a alguien.
 *
 * No es 422: no hay nada en el cuerpo que corregir. Los datos son correctos y la operación es legítima — lo que
 * falta es la firma de otra persona, y un 422 mandaría al usuario a revisar los campos, que es el sitio
 * equivocado.
 */
abstract class RequiresAuthorizationException extends RuntimeException
{
    /**
     * El permiso que el autorizador tiene que tener para conceder el PIN.
     *
     * Viaja en la respuesta para que el cliente no tenga que deducirlo del texto del mensaje ni llevar una tabla
     * propia de «qué permiso pide cada operación» — que se desincronizaría en la primera iteración.
     */
    abstract public function requiredPermission(): string;
}

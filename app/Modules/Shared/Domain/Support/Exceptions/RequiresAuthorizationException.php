<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support\Exceptions;

use RuntimeException;

/**
 * La operación exige la autorización de otra persona y no traía ninguna (ADR-008).
 *
 * Existe porque el **contrato HTTP tiene que ser uno solo**: `409` con `type: authorization_required` y el permiso que
 * hace falta. Si cada operación mapeara su propia excepción, el cliente que abre el diálogo del PIN tendría que
 * reconocer una respuesta distinta por operación, y la tercera se le olvidaría a alguien.
 *
 * No es 422: no hay nada en el cuerpo que corregir. Los datos son correctos y la operación es legítima — lo que falta es
 * la firma de otra persona, y un 422 mandaría al usuario a revisar los campos, que es el sitio equivocado.
 *
 * ## Por qué vive en el KERNEL
 *
 * Nació en `Inventory`, con las mermas (D170) y el cierre de conteos. Al llegar el POS —retiros de caja, descuentos,
 * cancelación post-comanda, cajón de dinero— resultó que `Pos` tendría que importar una clase de `Inventory` para
 * lanzar una excepción de contrato HTTP, y eso habría metido una flecha de dependencia entre dos módulos que no tienen
 * nada que ver.
 *
 * Es el mismo razonamiento de D231 aplicado a las excepciones: un contrato que cruza módulos vive donde no depende de
 * nadie. Las subclases se quedan en su módulo —cada una sabe de qué operación habla— y el traductor a HTTP es uno, en
 * el kernel.
 */
abstract class RequiresAuthorizationException extends RuntimeException
{
    /**
     * El permiso que el autorizador tiene que tener para conceder el PIN.
     *
     * Viaja en la respuesta para que el cliente no tenga que deducirlo del texto del mensaje ni llevar una tabla propia
     * de «qué permiso pide cada operación» — que se desincronizaría en la primera iteración.
     */
    abstract public function requiredPermission(): string;
}

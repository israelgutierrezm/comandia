<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use DomainException;

/**
 * Una regla de ruteo mal formada (D240).
 *
 * ## Por qué NO es una `PosAccountException`
 *
 * Porque responde con otro código y el código es el mensaje. `PosAccountException` es 409: «el estado del negocio no
 * admite lo que pediste», y lo que hay que hacer es volver a cargar. Esto es 422: los datos que mandaste no forman una
 * regla, y lo que hay que hacer es corregir el formulario.
 *
 * La lección viene del paso 4 de esta iteración, donde reusé `PaymentMethodInvariantException` para las categorías de
 * gasto y eso destapó que una excepción sin registrar responde 500. Una excepción por clase de problema, cada una con su
 * mapeo, y el mapeo verificado.
 */
final class PosAreaRouteException extends DomainException
{
    /**
     * Una regla es de un artículo **o** de una categoría.
     *
     * Con ambos vacíos no rutea nada; con ambos llenos hay dos respuestas para la misma pregunta y el orden de
     * resolución las vuelve impredecibles. La base tiene el mismo CHECK: esto existe para que el error diga qué hacer en
     * lugar de salir como un fallo de constraint.
     */
    public static function target(): self
    {
        return new self(
            'Una regla de ruteo apunta a un artículo o a una categoría, no a las dos ni a ninguna. Elige una: la del '
            .'artículo gana sobre la de su categoría.',
        );
    }

    /**
     * El área tiene que ser de la misma sucursal que la regla.
     *
     * Es la razón de que esta tabla exista (D240): las áreas son por sucursal. Una regla de la sucursal Centro que
     * apunte a la cocina de Sur mandaría las comandas de Centro a imprimirse en Sur — y nadie en Centro sabría por qué
     * la cocina no recibe nada.
     */
    public static function areaFromAnotherBranch(string $area, string $branch): self
    {
        return new self(sprintf(
            'El área «%s» no pertenece a la sucursal %s. Las áreas de preparación son de una sucursal, y una regla que '
            .'cruce sucursales mandaría las comandas a imprimirse en el local equivocado.',
            $area,
            $branch,
        ));
    }
}

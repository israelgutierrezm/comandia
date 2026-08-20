<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * El consumo pasa del límite de crédito del cliente (§8.3, ADR-008).
 *
 * ## 409 y no 422, y el diseño lo dice explícitamente
 *
 * No hay nada que corregir en el formulario: el cliente es el correcto, el monto es el correcto y el método es el
 * correcto. Lo que falta es que **alguien autorice** pasarse del límite — que es una decisión del negocio y no un error
 * de captura.
 *
 * Es el cuarto uso del contrato de ADR-008: mermas, cierre de conteos, gastos y ahora crédito. Cuatro operaciones de
 * cuatro módulos distintos compartiendo el mecanismo sin conocerse.
 */
final class CreditLimitRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forCustomer(string $customer, string $available, string $amount): self
    {
        return new self(sprintf(
            'A «%s» le quedan %s de crédito y este consumo es de %s. Pasarse del límite necesita la autorización de un '
            .'superior con su PIN.',
            $customer,
            $available,
            $amount,
        ));
    }

    public function requiredPermission(): string
    {
        return 'finance.customer_credit.manage';
    }
}

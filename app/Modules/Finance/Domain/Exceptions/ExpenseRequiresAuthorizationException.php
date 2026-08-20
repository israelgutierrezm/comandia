<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * El gasto pasa del umbral y no traía autorización (§6.5, ADR-008).
 *
 * ## CON umbral, a diferencia del cajón y los descuentos
 *
 * Y la razón es la misma que en las mermas (D27, D170), invertida: si todo gasto pidiera PIN, el cajero dejaría de
 * registrar los 40 pesos de hielo para no ir a buscar al gerente. El dinero sale igual y el arqueo se descuadra **sin
 * rastro** — que es peor que un gasto registrado sin autorizar.
 *
 * El umbral es por sucursal (`finance.expense_authorization_threshold`) porque el gasto corriente de un bar y de una
 * fonda no se parecen.
 *
 * Es el tercer uso del mismo contrato de ADR-008 —mermas, cierre de conteos, gastos— y eso confirma que estaba bien
 * planteada: tres operaciones de tres módulos distintos comparten el mecanismo sin conocerse.
 */
final class ExpenseRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forAmount(string $amount, string $threshold): self
    {
        return new self(sprintf(
            'Un gasto de %s pasa del umbral de %s y necesita la autorización de un superior con su PIN.',
            $amount,
            $threshold,
        ));
    }

    public function requiredPermission(): string
    {
        return 'finance.expenses.authorize_above_threshold';
    }
}

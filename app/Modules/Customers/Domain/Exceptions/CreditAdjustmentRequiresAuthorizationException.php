<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * Un ajuste de crédito sin el PIN de quien lo autoriza (D378, ADR-008).
 *
 * SIEMPRE pide firma, sin umbral: un ajuste cambia lo que un cliente debe sin que entre ni salga un peso. Condonar 50
 * pesos o 5 000 es la misma decisión —perdonar dinero—, y es la puerta por la que una deuda desaparece sin rastro.
 */
final class CreditAdjustmentRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forCustomer(string $customer, string $signedAmount): self
    {
        return new self(sprintf(
            'Ajustar en %s el saldo de «%s» cambia lo que debe sin que entre dinero: necesita la autorización de un '
            .'superior con su PIN.',
            $signedAmount,
            $customer,
        ));
    }

    public function requiredPermission(): string
    {
        return 'finance.customer_credit.adjust';
    }
}

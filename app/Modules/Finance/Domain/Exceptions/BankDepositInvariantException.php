<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

/**
 * Se intentó registrar un depósito que no encaja (§6.5).
 */
final class BankDepositInvariantException extends FinanceInvariantException
{
    public static function actorRequired(): self
    {
        return new self('No hay una persona en contexto que registre el depósito.');
    }
}

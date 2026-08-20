<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use App\Modules\Finance\Domain\Enums\FinancialMovementType;

/**
 * Se intentó asentar en el diario algo que ADR-004 no admite.
 *
 * Vive en el dominio y no en un Form Request porque **nadie llega al diario por HTTP**: quien asienta es un oyente de
 * evento, y un oyente no valida formularios. Una regla que sólo viviera en la capa HTTP no protegería nada aquí.
 */
final class FinancialMovementInvariantException extends FinanceInvariantException
{
    public static function zeroAmount(FinancialMovementType $type): self
    {
        return new self(sprintf(
            'Un asiento de %s en cero no dice nada: si no hubo dinero, no hubo hecho. Un corte que cuadra no asienta '
            .'una diferencia de cero, simplemente no asienta nada.',
            mb_strtolower($type->label()),
        ));
    }

    /**
     * El monto llegó con el signo contrario al sentido del tipo.
     *
     * Un cambio en positivo o un retiro en positivo hacen que el arqueo cuadre al revés: el «esperado» de efectivo sale
     * mayor de lo que hay en el cajón, y la diferencia se le achaca al cajero. Nada falla, y el número está mal.
     */
    public static function wrongSign(FinancialMovementType $type, string $amount, bool $esReversa = false): self
    {
        $esperado = $esReversa ? -$type->naturalSign() : $type->naturalSign();

        return new self(sprintf(
            'Un %sasiento de tipo «%s» debe registrarse en %s y llegó %s. Aplica `naturalSign()` del tipo antes de '
            .'asentar: un cambio o un retiro en positivo hacen que el arqueo cuadre al revés.',
            $esReversa ? 'contra-' : '',
            $type->label(),
            $esperado > 0 ? 'positivo' : 'negativo',
            $amount,
        ));
    }

    public static function sessionRequired(FinancialMovementType $type): self
    {
        return new self(sprintf(
            'Un movimiento de tipo «%s» pertenece siempre a una sesión de caja (§6.3): sin ella, el arqueo no puede '
            .'atribuirlo a ningún turno y el corte de ese día quedaría corto.',
            $type->label(),
        ));
    }
}

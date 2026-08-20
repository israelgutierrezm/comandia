<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Exceptions;

use App\Modules\Customers\Domain\Enums\CreditMovementType;
use DomainException;

/**
 * Se intentó mover un saldo de crédito de una forma que no encaja.
 */
final class CreditInvariantException extends DomainException
{
    public static function zeroAmount(): self
    {
        return new self('Un movimiento de crédito de cero no dice nada: si no hubo dinero, no hubo hecho.');
    }

    /**
     * El signo no corresponde al tipo.
     *
     * Se comprueba en lugar de corregirse en silencio, por la misma razón que en el diario financiero (D253): aplicar
     * el signo automáticamente escondería que quien llama entendió mal el sentido del movimiento. Un abono en positivo
     * aumentaría la deuda del cliente, y nadie lo notaría hasta que él reclamara.
     */
    public static function wrongSign(CreditMovementType $type, string $amount): self
    {
        return new self(sprintf(
            'Un movimiento de tipo «%s» debe registrarse en %s y llegó %s. Un cargo suma a lo que el cliente debe; un '
            .'abono resta.',
            $type->label(),
            $type->naturalSign() > 0 ? 'positivo' : 'negativo',
            $amount,
        ));
    }

    public static function noCreditAccount(string $customer): self
    {
        return new self(sprintf(
            'El cliente «%s» no tiene crédito habilitado. Asígnale un límite antes de fiarle un consumo.',
            $customer,
        ));
    }

    public static function repaymentNeedsSession(): self
    {
        return new self(
            'No hay una caja abierta en esta sucursal. Un abono entra al efectivo del turno y el arqueo tiene que '
            .'conocerlo: sin sesión sería dinero que entró a ningún cajón.',
        );
    }

    /**
     * No se abona más de lo que se debe.
     *
     * Un saldo negativo no significa nada aquí: el negocio no le debe dinero al cliente por haber pagado de más, le
     * debe un cambio en el momento. Se rechaza y quien cobra ajusta la cifra.
     */
    public static function repaymentAboveBalance(string $monto, string $saldo): self
    {
        return new self(sprintf(
            'El abono de %s es mayor que el saldo de %s. Un abono de más dejaría el saldo en negativo, que no significa '
            .'nada: si el cliente entregó de más, dale su cambio.',
            $monto,
            $saldo,
        ));
    }

    public static function creditDisabled(string $customer): self
    {
        return new self(sprintf(
            'El crédito de «%s» está suspendido. Su límite sigue ahí: vuelve a habilitarlo para poder fiarle.',
            $customer,
        ));
    }
}

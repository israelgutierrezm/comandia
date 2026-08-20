<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

/**
 * Se intentó liquidar una propina de una forma que no encaja (§6.6).
 */
final class TipSettlementInvariantException extends FinanceInvariantException
{
    public static function actorRequired(): self
    {
        return new self('No hay una persona en contexto que esté entregando la propina.');
    }

    public static function notPositive(): self
    {
        return new self('Una liquidación de cero no entrega nada.');
    }

    public static function needsSession(): self
    {
        return new self(
            'No hay una caja abierta en esta sucursal. La propina se paga en efectivo del cajón: sin turno, el arqueo '
            .'no sabría que salió.',
        );
    }

    /**
     * No se liquida más de lo que se debe.
     *
     * Se comprueba con el disponible recalculado DENTRO de la transacción: entre que la pantalla lo mostró y el cajero
     * apretó el botón, otra terminal pudo liquidar lo mismo — y sin esto se pagaría dos veces.
     */
    public static function aboveAvailable(string $monto, string $disponible): self
    {
        return new self(sprintf(
            'Se intentó liquidar %s y sólo hay %s pendientes. Si la pantalla mostraba otra cifra, vuelve a cargarla: '
            .'otra caja pudo haber liquidado parte hace un momento.',
            $monto,
            $disponible,
        ));
    }
}

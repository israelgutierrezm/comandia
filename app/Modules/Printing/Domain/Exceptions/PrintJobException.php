<?php

declare(strict_types=1);

namespace App\Modules\Printing\Domain\Exceptions;

use DomainException;

/**
 * Se pidió algo que la impresión no admite.
 */
final class PrintJobException extends DomainException
{
    public static function printerWithoutDrawer(string $printer): self
    {
        return new self(sprintf(
            'La impresora «%s» no tiene cajón de dinero conectado. El cajón se abre mandando una secuencia a una '
            .'impresora que lo soporte; elige la de la caja.',
            $printer,
        ));
    }

    public static function transitionNotAllowed(string $from, string $to): self
    {
        return new self(sprintf(
            'Un trabajo de impresión %s no puede pasar a %s.',
            mb_strtolower($from),
            mb_strtolower($to),
        ));
    }

    /**
     * El agente reporta un trabajo que no reclamó.
     *
     * Importa porque con dos agentes en la misma sucursal el segundo podría reportar el papel del primero, y entonces
     * un trabajo que nunca se imprimió quedaría marcado como impreso. La cocina no recibiría nada y el sistema diría
     * que sí.
     */
    public static function notClaimedByAgent(string $agent): self
    {
        return new self(sprintf(
            'El agente «%s» no reclamó este trabajo, así que no puede reportar su resultado.',
            $agent,
        ));
    }
}

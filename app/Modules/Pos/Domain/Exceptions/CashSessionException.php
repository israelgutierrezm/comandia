<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use DomainException;

/**
 * Se intentó algo que una caja no admite (§6.3).
 *
 * Se lanza desde el servicio y no desde el controlador: el POS va a llamar a estas operaciones desde la pantalla de
 * caja, desde el cobro y —cuando llegue— desde la app, y la regla tiene que valer igual en los tres.
 */
final class CashSessionException extends DomainException
{
    public static function terminalAlreadyOpen(string $terminalCode, string $folio): self
    {
        return new self(sprintf(
            'La caja %s ya tiene un turno abierto (%s). Ciérralo antes de abrir otro: dos turnos simultáneos en la '
            .'misma caja producen dos cortes que se pisan, y el segundo cuadra contra un efectivo que el primero ya '
            .'contó.',
            $terminalCode,
            $folio,
        ));
    }

    public static function sessionAlreadyClosed(string $folio): self
    {
        return new self(sprintf(
            'El turno %s ya está cerrado. Un turno cerrado no admite cobros, retiros ni declaraciones: su corte ya se '
            .'calculó sobre lo que había.',
            $folio,
        ));
    }

    public static function closeNeedsDeclarations(string $folio): self
    {
        return new self(sprintf(
            'Para cerrar el turno %s hace falta declarar lo que hay en caja. Sin la declaración no hay arqueo posible '
            .'—el corte compara lo declarado contra lo esperado— y quedaría un turno que sólo dice que terminó.',
            $folio,
        ));
    }

    public static function membershipRequired(): self
    {
        return new self(
            'No hay una persona en contexto a la que atribuir esta operación de caja. Un arqueo sin actor no sirve '
            .'para nada.'
        );
    }
}

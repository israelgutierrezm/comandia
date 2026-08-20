<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use DomainException;

/**
 * Se quiso registrar un gasto desde caja sin turno abierto.
 *
 * ## Responde 409 y no 422, y la diferencia importa
 *
 * Los datos que llegaron son correctos: la sucursal existe, la categoría existe, el monto es válido. Lo que no encaja es
 * el **estado del negocio** — no hay caja abierta— y lo que hay que hacer no es corregir el formulario sino abrir la
 * caja. Un 422 mandaría a quien registra a revisar campos que están bien.
 *
 * Es la misma respuesta que da el POS al intentar cobrar sin turno (`PosAccountException::noOpenSession`), y son dos
 * clases porque cada módulo traduce sus propias excepciones a HTTP (§2): `Finance` no puede lanzar una excepción de
 * `Pos` sin depender de él, que es justo el ciclo que `CashSessionProbe` existe para evitar.
 */
final class NoOpenCashSessionException extends DomainException
{
    public static function forExpense(): self
    {
        return new self(
            'No hay una caja abierta en esta sucursal. Un gasto desde caja pertenece a un turno: sin él sería dinero '
            .'que salió de ningún cajón y el arqueo no podría explicarlo.',
        );
    }
}

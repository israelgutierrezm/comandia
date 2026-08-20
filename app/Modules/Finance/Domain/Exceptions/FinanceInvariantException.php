<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

use DomainException;

/**
 * Base de los invariantes del módulo `Finance`.
 *
 * ## Por qué una base y no una excepción por concepto
 *
 * El proveedor del módulo traduce **una** clase a 422 en lugar de una por concepto, así que agregar un invariante nuevo
 * no exige acordarse de registrarlo. Esa omisión ya estaba a punto de ocurrir: `FinancialMovementInvariantException`
 * nació sin registro y habría devuelto **500** en su primer uso — el mismo defecto que este módulo acababa de corregir
 * para los métodos de pago.
 *
 * Las subclases existen igualmente porque distinguen de qué se está hablando, y una prueba puede exigir la concreta.
 */
abstract class FinanceInvariantException extends DomainException
{
}

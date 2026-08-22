<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Consumption;

use App\Modules\Shared\Domain\Contracts\ConsumptionHistoryProvider;

/**
 * El default: un cliente sin consumos.
 *
 * Se usa cuando `Pos` no está enlazado —una prueba que no lo levanta—. Devolver una lista vacía deja el expediente en
 * pie; que el historial falle no debe poder tumbar la ficha del cliente.
 */
final readonly class NullConsumptionHistoryProvider implements ConsumptionHistoryProvider
{
    public function forCustomer(int $customerId, int $limit): array
    {
        return [];
    }
}

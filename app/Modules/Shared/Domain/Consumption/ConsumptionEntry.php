<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Consumption;

/**
 * Un consumo del cliente: una cuenta del POS, en primitivos.
 *
 * Es lo que cruza del POS al expediente sin que `Customers` toque un modelo de `Pos`. Lleva la hora en UTC más la zona de
 * la sucursal para que la ficha la presente en hora local (la regla de siempre: se guarda en UTC, se muestra en la zona
 * de la sucursal).
 */
final readonly class ConsumptionEntry
{
    public function __construct(
        public string $accountUlid,
        public string $reference,
        public string $branchName,
        public string $branchTimezone,
        public string $occurredAt,
        public string $total,
        public string $status,
    ) {}
}

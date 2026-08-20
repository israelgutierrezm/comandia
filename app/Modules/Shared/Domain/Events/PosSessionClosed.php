<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se cerró una caja (§6.3).
 *
 * ## Lo que este evento NO lleva
 *
 * No lleva el esperado, ni lo declarado, ni la diferencia. El arqueo se **calcula** del diario (§6.5, ADR-004) y llega
 * en el paso 19 con su propio servicio; adelantarlo aquí obligaría a que el emisor supiera sumar el diario, que es
 * exactamente la responsabilidad que no le toca.
 *
 * Lo que sí lleva es el hecho: esta caja se cerró, a esta hora, y la cerró esta persona. Con eso, quien calcula el corte
 * tiene todo lo que necesita.
 */
final readonly class PosSessionClosed implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $sessionUlid,
        public int $sessionId,
        public int $branchId,
        public int $actorMembershipId,
        public string $closedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

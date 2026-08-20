<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se retiró efectivo de una caja abierta (§6.3).
 *
 * El retiro **sale** del cajón, así que el asiento va en negativo. El signo lo pone quien asienta usando el sentido
 * natural del tipo (`FinancialMovementType::naturalSign()`), y no el emisor: poner un retiro en positivo dejaría el
 * arqueo cuadrando al revés, y es el error más fácil de cometer.
 */
final readonly class PosWithdrawalRegistered implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $withdrawalUlid,
        public int $sessionId,
        public int $branchId,
        /** @var numeric-string */
        public string $amount,
        public string $reason,
        public int $actorMembershipId,
        public string $occurredAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

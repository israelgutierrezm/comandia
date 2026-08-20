<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un cliente abonó a su cuenta (§6.3, §8.3).
 *
 * **Afecta cajón al ocurrir**: el dinero entra a la caja como efectivo, así que el arqueo tiene que conocerlo. Es la
 * mitad que falta para que el «esperado» del corte cuadre — sin abonos, un turno que recibió dos mil pesos de fiado
 * daría dos mil de más y nadie sabría de dónde salieron.
 *
 * Lo emite `Customers` y lo asienta `Finance` como `credit_repayment`.
 */
final readonly class CustomerCreditRepaid implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $customerUlid,
        public string $movementUlid,

        /** @var numeric-string El monto abonado, en positivo. */
        public string $amount,

        public int $posSessionId,
        public int $paymentMethodId,
        public int $actorMembershipId,
        public string $repaidAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

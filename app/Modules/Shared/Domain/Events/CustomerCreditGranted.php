<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se fió un consumo a un cliente (§8.3).
 *
 * `Finance` lo asienta como `credit_granted`: **no mueve caja** —no entró dinero— pero sí es un derecho de cobro, y el
 * negocio necesita saber cuánto tiene fiado. Es lo que distingue «vendí 10 000» de «cobré 8 000 y me deben 2 000».
 *
 * ## El cargo al saldo NO viaja por aquí
 *
 * Lo hace `Pos` directamente, en la transacción del cobro, llamando a `Customers`. Y es deliberado: si el cargo se
 * hiciera por evento —después del commit—, una cuenta podría quedar pagada con el saldo del cliente sin cargar. El
 * negocio habría regalado la comida y el estado de cuenta no lo sabría.
 *
 * El evento existe para lo que **sí** puede llegar tarde: el asiento del diario, que si falla se repara re-despachando.
 */
final readonly class CustomerCreditGranted implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $customerUlid,
        public string $accountUlid,

        /** @var numeric-string */
        public string $amount,

        public int $posSessionId,
        public int $actorMembershipId,
        public string $grantedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

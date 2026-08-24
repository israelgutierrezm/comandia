<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Payments;

/**
 * El resultado de un reembolso: la referencia con que la pasarela lo identifica (su id de reembolso) y cuánto se devolvió.
 *
 * La referencia es DISTINTA de la del cobro: casar el reembolso por su propio id lo hace idempotente (un reintento choca
 * con la llave única de `payments`) y deja el reembolso rastreable en la pasarela.
 */
final readonly class RefundResult
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public string $reference,
        public string $amount,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Payments;

/**
 * El resultado de iniciar un cobro: a dónde mandar al cliente y con qué referencia la pasarela identificará el pago.
 */
final readonly class CheckoutIntent
{
    public function __construct(
        public string $redirectUrl,
        public string $reference,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Payments;

/**
 * El aviso de una pasarela, traducido a lo que el checkout necesita: qué pago (referencia), si quedó aprobado y por cuánto.
 */
final readonly class WebhookResult
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public string $reference,
        public bool $approved,
        public string $amount,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Payments;

/**
 * El aviso de una pasarela, traducido a lo que el checkout necesita: qué pago (referencia), si quedó aprobado, por cuánto, y
 * el id del cargo en la pasarela (`gatewayPaymentId`) —distinto de la referencia— para poder reembolsar después (D2 parte 2).
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
        public string $gatewayPaymentId = '',
    ) {}
}

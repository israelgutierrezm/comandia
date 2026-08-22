<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Promotions;

/**
 * Una promoción que aplica a una línea, con el monto que descuenta — la respuesta del motor, en primitivos.
 *
 * Es por LÍNEA porque los tres tipos de v1 apuntan a artículos o categorías, que se materializan en líneas concretas:
 * «10 % en Bebidas» sobre tres líneas de bebida son tres `AppliedPromotion`. El monto lo calculó el SERVIDOR (el motor),
 * nunca el cliente.
 */
final readonly class AppliedPromotion
{
    public function __construct(
        public string $promotionUlid,
        public string $name,
        public string $itemUlid,
        public string $resultingAmount,
    ) {}
}

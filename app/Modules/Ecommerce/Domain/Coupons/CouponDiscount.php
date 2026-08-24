<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Coupons;

use App\Modules\Ecommerce\Infrastructure\Models\Coupon;

/**
 * El efecto de un cupón sobre un pedido (Iteración 8, Tanda D, D3 parte 2).
 *
 * Separa el descuento **de productos** (que reduce el `OnlineSale`) del envío gratis (que pone el envío en cero, sin tocar
 * productos), porque el diario los trata distinto (D342). `amountDiscounted` es el beneficio total para el cliente, para el
 * log de canje.
 */
final readonly class CouponDiscount
{
    /**
     * @param  numeric-string  $productDiscount  descuento sobre el subtotal de productos
     * @param  numeric-string  $amountDiscounted  beneficio total (productos + envío ahorrado), para el log de canje
     */
    public function __construct(
        public Coupon $coupon,
        public string $productDiscount,
        public bool $waivesShipping,
        public string $amountDiscounted,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Enums;

/**
 * El tipo de descuento de un cupón de tienda (Iteración 8, Tanda D, D3). Catálogo cerrado, como los tipos de promoción de
 * la It.6: un cupón descuenta un porcentaje, un monto fijo, o el envío. El humano combina; no inventa tipos.
 */
enum CouponType: string
{
    /** Un porcentaje sobre el subtotal de productos. `value` es el %, de 1 a 100. */
    case Percentage = 'percentage';

    /** Un monto fijo sobre el subtotal de productos. `value` es el monto. */
    case Fixed = 'fixed';

    /** Envío gratis: pone el costo de envío en cero. `value` no se usa. */
    case FreeShipping = 'free_shipping';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Porcentaje',
            self::Fixed => 'Monto fijo',
            self::FreeShipping => 'Envío gratis',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain\Enums;

/**
 * El catálogo ACOTADO de tipos de promoción (D50).
 *
 * Es un enum cerrado a propósito: D50 dice «catálogo de tipos, no motor libre». Un tipo nuevo es una decisión de
 * producto y una migración, no un dato que un tenant inventa. Los cupones (§6.8) no están aquí: son de e-commerce y se
 * difieren a la Iteración 8 (D314).
 */
enum PromotionType: string
{
    /** Un porcentaje sobre el importe de los artículos/categorías objetivo. */
    case Percentage = 'percentage';

    /** Un monto fijo de descuento sobre el importe objetivo. */
    case Amount = 'amount';

    /** Compra N, paga M: 2x1 es buy=2 pay=1; 3x2 es buy=3 pay=2. Regala las unidades más baratas. */
    case Nxm = 'nxm';

    /** El artículo objetivo cuesta un precio especial mientras dure la ventana. */
    case SpecialPrice = 'special_price';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Descuento por porcentaje',
            self::Amount => 'Descuento por monto',
            self::Nxm => 'Compra N, paga M',
            self::SpecialPrice => 'Precio especial',
        };
    }
}

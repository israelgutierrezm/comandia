<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Qué clase de descuento es (§6.3).
 */
enum PosDiscountKind: string
{
    /** Un porcentaje sobre la base: `value` va de 0 a 100. */
    case Percentage = 'percentage';

    /** Un importe fijo: `value` son pesos. */
    case Amount = 'amount';

    /**
     * «Este plato va por la casa».
     *
     * Es un descuento del 100 % de una línea, y aun así tiene tipo propio por dos razones. La primera es que se cuenta
     * aparte: «cuánto regalé» y «cuánto descontué» son dos preguntas distintas, y una cortesía metida entre los
     * descuentos las mezcla. La segunda es que marca la línea con `is_courtesy`, y de ahí sale que una cortesía **sí
     * descuente inventario** (§6.3): el plato se preparó y los insumos se gastaron, aunque no se haya cobrado.
     */
    case Courtesy = 'courtesy';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Porcentaje',
            self::Amount => 'Importe',
            self::Courtesy => 'Cortesía',
        };
    }

    /** ¿Aplica siempre a una línea y nunca a la cuenta entera? */
    public function requiresItem(): bool
    {
        return $this === self::Courtesy;
    }
}

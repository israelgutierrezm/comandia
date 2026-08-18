<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Enums;

use App\Modules\Shared\Domain\Support\Decimal;

/**
 * Redondeo aplicado al precio sugerido (D15).
 *
 * Los valores coinciden con los de la llave de configuración `pricing.rounding_mode`, y no por casualidad:
 * el enum es la forma tipada de ese ajuste. Si divergieran, `from()` lanzaría y el fallo saldría a la luz
 * — preferible a un redondeo silencioso distinto del configurado.
 *
 * Redondea **hacia arriba** en los modos de múltiplo, no al más cercano. Es deliberado: un precio sugerido
 * es un piso de rentabilidad, y bajarlo para llegar al múltiplo más cercano recortaría el markup que el
 * negocio pidió. $47 con múltiplos de 5 sugiere $50, no $45.
 */
enum RoundingMode: string
{
    case None = 'none';
    case Integer = 'integer';
    case Multiple5 = 'multiple_5';
    case Multiple10 = 'multiple_10';

    /**
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    public function apply(string $amount): string
    {
        return match ($this) {
            // Dos decimales: es un monto y §7 fija los montos en DECIMAL(12,2).
            self::None => Decimal::round($amount, 2),

            self::Integer => $this->ceilToMultiple($amount, '1'),
            self::Multiple5 => $this->ceilToMultiple($amount, '5'),
            self::Multiple10 => $this->ceilToMultiple($amount, '10'),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin redondeo',
            self::Integer => 'Al peso',
            self::Multiple5 => 'A múltiplos de $5',
            self::Multiple10 => 'A múltiplos de $10',
        };
    }

    /**
     * Redondea hacia arriba al siguiente múltiplo.
     *
     * Con `bcmath` y no con `ceil()`: `ceil()` convierte a float, y el proyecto hace esta aritmética con
     * cadenas justamente para no pasar por float (§7, P3).
     *
     * @param  numeric-string  $amount
     * @param  numeric-string  $multiple
     * @return numeric-string
     */
    private function ceilToMultiple(string $amount, string $multiple): string
    {
        // Cociente truncado: bcdiv con escala 0 trunca hacia cero, que para un monto positivo es el piso.
        $quotient = bcdiv($amount, $multiple, 0);
        $floor = bcmul($quotient, $multiple, 2);

        // Ya es múltiplo exacto: no se sube al siguiente. Sin esta comprobación, $50 con múltiplos de 5
        // sugeriría $55 — subir el precio de algo que ya estaba redondo.
        if (bccomp($floor, $amount, 2) === 0) {
            return Decimal::round($floor, 2);
        }

        return Decimal::round(bcadd($floor, $multiple, 2), 2);
    }
}

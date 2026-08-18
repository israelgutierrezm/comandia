<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support;

/**
 * Redondeo decimal exacto sobre cadenas.
 *
 * Vive en el kernel porque lo necesitan `Catalog` y `Costing`, y lo necesitarán inventarios y
 * finanzas: cualquier módulo puede depender del kernel (§2, regla 1).
 *
 * ## Por qué no `round()`
 *
 * `round()` convierte a float, y el proyecto hace la aritmética de costos con `bcmath` justo para no
 * pasar por float (§7, P3). Redondear con `round()` en la frontera reintroduciría el error que la
 * decisión evita, precisamente en el paso donde el número se guarda y ya no se puede corregir.
 *
 * `bcmath` **trunca** en lugar de redondear, así que 0.12345 a 4 decimales daría 0.1234 y no 0.1235.
 * Truncar sistemáticamente sesga los costos hacia abajo: cada insumo de cada receta pierde una
 * fracción, siempre en el mismo sentido, y el margen que el sistema reporta acaba siendo optimista.
 */
final class Decimal
{
    /**
     * Redondea media-arriba (half-up), el criterio que espera un humano.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    public static function round(string $value, int $scale): string
    {
        $isNegative = str_starts_with($value, '-');
        $absolute = $isNegative ? substr($value, 1) : $value;

        // Sumar medio dígito de la escala destino y truncar equivale a redondear media-arriba, y
        // es la forma canónica de hacerlo con bcmath.
        $half = '0.'.str_repeat('0', $scale).'5';

        /** @var numeric-string $rounded */
        $rounded = bcadd($absolute, $half, $scale + 1);
        /** @var numeric-string $rounded */
        $rounded = bcadd($rounded, '0', $scale);

        return $isNegative && bccomp($rounded, '0', $scale) !== 0
            ? '-'.$rounded
            : $rounded;
    }

    /**
     * Divide redondeando media-arriba a la escala pedida.
     *
     * ## Por qué existe, y por qué no basta `bcdiv` seguido de `round`
     *
     * `bcdiv($a, $b, 8)` **trunca** al octavo decimal. Redondear después a esa misma escala no corrige
     * nada: el dígito que habría decidido el redondeo ya se perdió. Hay que dividir con MÁS escala de la
     * que se quiere y redondear al final.
     *
     * Se descubrió costeando tres niveles en cascada: 10 ÷ 600 daba 0.01666666 en lugar de 0.01666667, y
     * ese truncamiento se propagaba hacia arriba hasta mover el cuarto decimal del costo del platillo. El
     * sesgo es siempre hacia abajo, así que el margen reportado sale optimista sin que nada falle.
     *
     * @param  numeric-string  $dividend
     * @param  numeric-string  $divisor
     * @return numeric-string
     */
    public static function divide(string $dividend, string $divisor, int $scale): string
    {
        // Dos dígitos de guarda bastan para que el redondeo vea el dígito decisivo.
        return self::round(bcdiv($dividend, $divisor, $scale + 2), $scale);
    }

    /**
     * ¿Son iguales dos cadenas decimales a la escala indicada?
     *
     * Comparar con `==` fallaría entre '16.00' y '16.0000', que son el mismo número y distintas
     * cadenas — el caso que aparece al comparar lo que se escribió con lo que MySQL devuelve.
     *
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     */
    public static function equals(string $a, string $b, int $scale = 4): bool
    {
        return bccomp($a, $b, $scale) === 0;
    }
}

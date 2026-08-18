<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Domain\Exceptions\IncompatibleUnitDimensionException;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * Conversión entre unidades de medida (D22).
 *
 * Es la pieza de la que depende **todo costo del sistema**: un error aquí no produce un error
 * visible, produce costos equivocados en cada receta, y de ahí precios sugeridos equivocados y
 * márgenes equivocados. Por eso es dominio puro —sin base de datos, sin contexto— y tiene su propia
 * suite.
 *
 * ## Aritmética con `bcmath`, no con `float`
 *
 * Los factores llevan 8 decimales y las cantidades 4, y una receta anidada acumula multiplicaciones
 * y divisiones nivel por nivel. Con `float` el error es pequeño en cada paso y perfectamente capaz de
 * moverse al segundo decimal del costo de un platillo después de tres niveles de cascada — y un costo
 * que "casi" cuadra es peor que uno que no cuadra, porque nadie lo investiga.
 *
 * Se trabaja con cadenas a {@see self::SCALE} decimales y se redondea sólo al persistir o al
 * presentar. Es también la razón por la que los métodos devuelven `string` y no `float`: convertir a
 * float en la frontera reintroduciría exactamente el problema que `bcmath` evita.
 */
final class UnitConverter
{
    /**
     * Decimales de trabajo.
     *
     * Ocho, no cuatro: es la escala del factor de conversión (`units.factor_to_base`), y truncar los
     * intermedios a la escala de almacenamiento haría que convertir de ida y de vuelta no devolviera
     * el valor original.
     */
    public const SCALE = 8;

    /**
     * Convierte una cantidad de una unidad a otra de la misma dimensión.
     *
     * @param  numeric-string|float|int  $quantity
     * @return numeric-string la cantidad en `$to`, con {@see self::SCALE} decimales
     *
     * @throws IncompatibleUnitDimensionException
     */
    public function convert(string|float|int $quantity, Unit $from, Unit $to): string
    {
        $this->assertSameDimension($from, $to);

        if ($from->id === $to->id) {
            return $this->normalize($quantity);
        }

        // cantidad × (factor del origen ÷ factor del destino). Los dos factores son relativos a la
        // MISMA base del sistema, así que la base se cancela y no hace falta materializarla.
        $inBase = bcmul($this->normalize($quantity), $this->normalize($from->factor_to_base), self::SCALE);

        // `Decimal::divide` y no `bcdiv` a secas: `bcdiv` trunca, y truncar la conversión sesga la cantidad
        // —y con ella el costo— siempre hacia abajo. Con una unidad de factor 3, convertir 2 daría
        // 0.66666666 en lugar de 0.66666667. Es el mismo defecto que apareció costeando en cascada, y aquí
        // afectaría a TODA cantidad convertida del sistema.
        return Decimal::divide($inBase, $this->normalize($to->factor_to_base), self::SCALE);
    }

    /**
     * Convierte a la unidad base del SISTEMA para la dimensión de `$from`.
     *
     * La usa el costeo cuando necesita comparar cantidades expresadas en unidades distintas sin
     * elegir una de ellas como referencia.
     *
     * @param  numeric-string|float|int  $quantity
     * @return numeric-string
     */
    public function toSystemBase(string|float|int $quantity, Unit $from): string
    {
        return bcmul($this->normalize($quantity), $this->normalize($from->factor_to_base), self::SCALE);
    }

    /**
     * ¿Se puede convertir entre estas dos unidades?
     *
     * Existe para que la validación de un Form Request pueda preguntar sin provocar una excepción:
     * un formulario responde con un error de campo, no con un 500.
     */
    public function isCompatible(Unit $from, Unit $to): bool
    {
        return $from->dimension === $to->dimension;
    }

    /**
     * @throws IncompatibleUnitDimensionException
     */
    private function assertSameDimension(Unit $from, Unit $to): void
    {
        if ($from->dimension !== $to->dimension) {
            throw IncompatibleUnitDimensionException::between(
                $from->code,
                $from->dimension->label(),
                $to->code,
                $to->dimension->label(),
            );
        }
    }

    /**
     * Lleva cualquier entrada numérica a una cadena con la escala de trabajo.
     *
     * `bcmath` opera sobre cadenas y un `float` interpolado puede llegar en notación científica
     * (`1.0E-5`), que `bcmath` lee como 1. Ese caso concreto convertiría una cantidad diminuta en
     * una diez mil veces mayor, sin error.
     *
     * @param  numeric-string|float|int  $value
     * @return numeric-string
     */
    private function normalize(string|float|int $value): string
    {
        if (is_string($value)) {
            /** @var numeric-string */
            return $value;
        }

        /** @var numeric-string */
        return number_format((float) $value, self::SCALE, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Exceptions;

use DomainException;

/**
 * Se intentó convertir entre unidades de dimensiones distintas.
 *
 * No es un caso a resolver con una aproximación: convertir piezas a kilogramos depende del artículo
 * —un limón no pesa lo que una sandía—, así que una conversión global sería una mentira con forma de
 * número. Ese caso se modela con presentaciones de compra, que son por artículo.
 */
final class IncompatibleUnitDimensionException extends DomainException
{
    public static function between(string $fromCode, string $fromDimension, string $toCode, string $toDimension): self
    {
        return new self(sprintf(
            'No se puede convertir de «%s» (%s) a «%s» (%s): son magnitudes distintas. '.
            'Si el artículo se compra en una y se usa en otra, captúralo como presentación de compra.',
            $fromCode,
            $fromDimension,
            $toCode,
            $toDimension,
        ));
    }
}

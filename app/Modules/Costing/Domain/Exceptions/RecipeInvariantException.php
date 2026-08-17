<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Exceptions;

use DomainException;

/**
 * La receta viola un invariante del dominio.
 *
 * Se lanza desde el servicio de aplicación y no sólo desde el Form Request, por lo mismo que los
 * invariantes del artículo: un Form Request protege el camino HTTP, y estas reglas también tienen que
 * valer para importaciones y servicios internos.
 */
final class RecipeInvariantException extends DomainException
{
    /**
     * Sólo un artículo producible tiene receta.
     */
    public static function articleIsNotProducible(string $articleName): self
    {
        return new self(sprintf(
            '«%s» no está marcado como producible, así que no puede tener receta. Marca la capacidad '.
            'primero: una receta en un artículo que no se produce es un costo que nadie usaría.',
            $articleName,
        ));
    }

    /**
     * Invariante I5: un componente tiene que poder usarse como insumo.
     */
    public static function componentIsNotSupply(string $articleName): self
    {
        return new self(sprintf(
            '«%s» no está marcado como insumo, así que no puede ser ingrediente. Es la doble modalidad '.
            'de D16: un ingrediente es un insumo con costo capturado, o un producible con receta propia.',
            $articleName,
        ));
    }

    /**
     * Invariante I3: la unidad de la línea comparte magnitud con la unidad base del componente.
     */
    public static function lineUnitMismatch(
        string $componentName,
        string $lineUnit,
        string $baseUnit,
    ): self {
        return new self(sprintf(
            'La cantidad de «%s» está en %s, pero ese artículo se mide en %s: son magnitudes distintas '.
            'y no hay forma de convertirlas sin inventar un peso. Si lo compras en una unidad y lo usas '.
            'en otra, captúralo como presentación de compra.',
            $componentName,
            $lineUnit,
            $baseUnit,
        ));
    }

    /**
     * La unidad de rendimiento comparte magnitud con la unidad base del artículo.
     */
    public static function outputUnitMismatch(string $outputUnit, string $baseUnit): self
    {
        return new self(sprintf(
            'La receta rinde en %s pero el artículo se mide en %s. Sin una magnitud común, el costo por '.
            'unidad del artículo no se puede calcular.',
            $outputUnit,
            $baseUnit,
        ));
    }

    public static function withoutLines(): self
    {
        return new self(
            'Una receta necesita al menos un ingrediente: sin ninguno, el costo del artículo sería cero '.
            'y el sistema sugeriría venderlo gratis.'
        );
    }
}

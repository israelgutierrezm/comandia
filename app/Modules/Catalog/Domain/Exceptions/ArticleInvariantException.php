<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Exceptions;

use DomainException;

/**
 * Un artículo se intentó guardar en un estado que el dominio no admite.
 *
 * Se lanza desde el modelo y no sólo desde el Form Request, a propósito: un Form Request protege el
 * camino HTTP, y estas reglas también tienen que valer para seeders, importaciones y cualquier
 * servicio interno. Una regla que sólo vive en la capa HTTP es una regla que la primera importación
 * masiva se salta.
 */
final class ArticleInvariantException extends DomainException
{
    /**
     * Invariante I2 del diseño.
     */
    public static function sellableWithoutPrice(): self
    {
        return new self(
            'Un artículo vendible necesita precio. Puedes capturarlo sin precio y ponérselo después, '.
            'pero no marcarlo como vendible sin él.'
        );
    }

    /**
     * Invariante I11 del diseño (P11).
     */
    public static function sellableWithoutCategory(): self
    {
        return new self(
            'Un artículo vendible necesita categoría: el punto de venta agrupa la pantalla por '.
            'categoría y sin ella no tendría dónde aparecer.'
        );
    }

    /**
     * Invariante I6 del diseño, en su forma estricta.
     */
    public static function baseUnitIsImmutable(): self
    {
        return new self(
            'La unidad base de un artículo no se puede cambiar: todas sus cantidades históricas '.
            '—costos, recetas, existencias y ventas— están expresadas en ella. Si está equivocada, '.
            'archiva el artículo y captura uno nuevo.'
        );
    }
}

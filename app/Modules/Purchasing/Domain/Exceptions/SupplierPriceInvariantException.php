<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\Exceptions;

use RuntimeException;

/**
 * La observación de precio no se puede registrar tal como se pidió.
 *
 * Son invariantes del dominio y no de la captura: dependen del estado del proveedor y de la presentación en el instante
 * de escribir, y el historial es inmutable — una observación mal escrita no se puede corregir, sólo se le puede
 * agregar otra encima. Por eso se comprueban antes de escribir y no después.
 */
final class SupplierPriceInvariantException extends RuntimeException
{
    public static function inactiveSupplier(string $supplier): self
    {
        return new self(sprintf(
            '«%s» está dado de baja: no se le pueden registrar precios nuevos. Su historial sigue ahí y se puede '
            .'consultar; si le vuelves a comprar, reactívalo primero.',
            $supplier,
        ));
    }

    public static function nonPositivePrice(): self
    {
        return new self(
            'El precio tiene que ser mayor que cero. Un cero no es un precio bajo, es la ausencia de precio, y en la '
            .'comparación entre proveedores saldría siempre como el más barato.'
        );
    }

    public static function presentationWithoutQuantity(string $presentation): self
    {
        return new self(sprintf(
            'La presentación «%s» no tiene una cantidad válida en unidad base, así que el precio por unidad no se '
            .'puede calcular. Corrígela en el catálogo.',
            $presentation,
        ));
    }

    public static function presentationIsNotOfArticle(): self
    {
        return new self(
            'Esa presentación no es de este artículo. Mezclarlas normalizaría el precio con la cantidad equivocada, y '
            .'el historial quedaría con un precio por unidad que no corresponde a nada.'
        );
    }
}

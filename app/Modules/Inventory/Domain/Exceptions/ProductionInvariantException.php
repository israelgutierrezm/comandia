<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

/**
 * La producción no se puede registrar tal como se pidió.
 *
 * Son invariantes del documento y del catálogo, no de la captura: dependen del estado de la receta y de la orden en el
 * instante de producir. El servicio las comprueba con la fila bloqueada.
 */
final class ProductionInvariantException extends RuntimeException
{
    public static function notProducible(string $article): self
    {
        return new self(sprintf(
            '«%s» no está marcado como producible. Márcalo en el catálogo antes de producirlo: producir un artículo '
            .'que el negocio no declaró producible consumiría insumos sin que nadie lo hubiera decidido.',
            $article,
        ));
    }

    public static function withoutRecipe(string $article): self
    {
        return new self(sprintf(
            '«%s» no tiene receta activa. Sin receta no hay nada que consumir, y una producción que sólo genera '
            .'existencia sin gastar insumos es una entrada manual — regístrala como tal.',
            $article,
        ));
    }

    public static function emptyRecipe(string $article): self
    {
        return new self(sprintf(
            'La receta de «%s» no tiene ingredientes. Captúralos antes de producir.',
            $article,
        ));
    }

    public static function componentIsNotInventoriable(string $component): self
    {
        return new self(sprintf(
            '«%s» aparece en la receta pero no se inventaría, así que no tiene existencia que consumir. Márcalo como '
            .'inventariable, o quítalo de la receta y pon en su lugar los insumos que lo componen.',
            $component,
        ));
    }

    public static function notOpen(): self
    {
        return new self(
            'Esta orden de producción ya se completó o se canceló. Sus movimientos están en el kardex, que no admite '
            .'corrección: para rehacerla, produce en sentido inverso con otra orden.'
        );
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('La cantidad producida tiene que ser mayor que cero.');
    }

    public static function recipeYieldsNothing(string $article): self
    {
        return new self(sprintf(
            'La receta de «%s» declara un rendimiento de cero, así que no se puede escalar a la cantidad que se '
            .'quiere producir. Corrige el rendimiento de la receta.',
            $article,
        ));
    }
}

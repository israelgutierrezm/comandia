<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use RuntimeException;

/**
 * Un movimiento de inventario que violaría un invariante del kardex.
 *
 * Los mensajes están escritos para quien los va a leer en un 422, no para un log: dicen qué se intentó y por
 * qué no se puede, porque la mayoría de estos casos llegan de una integración o de un job y quien depura no
 * tiene el contexto a la vista.
 */
final class StockMovementInvariantException extends RuntimeException
{
    public static function quantityMustBePositive(string $quantity): self
    {
        return new self(
            "La cantidad de un movimiento tiene que ser mayor que cero, y llegó «{$quantity}». La dirección "
            .'del movimiento la decide su tipo: para restar existencia se registra una salida, no una '
            .'cantidad negativa.'
        );
    }

    public static function directionContradictsKind(
        StockMovementKind $kind,
        StockMovementDirection $direction,
    ): self {
        $fija = $kind->fixedDirection();

        return new self(sprintf(
            'Un movimiento de tipo «%s» es siempre una %s, y se pidió registrarlo como %s. Si lo que se '
            .'quiere es el movimiento contrario, es otro tipo: una merma no se deshace sumando una merma.',
            $kind->label(),
            mb_strtolower($fija?->label() ?? ''),
            mb_strtolower($direction->label()),
        ));
    }

    public static function directionRequired(StockMovementKind $kind): self
    {
        return new self(sprintf(
            'Un movimiento de tipo «%s» necesita dirección explícita: un ajuste puede sumar o restar, y ahí '
            .'el signo ES la información. Sin valor por omisión, porque elegir uno haría que un ajuste '
            .'restara en silencio.',
            $kind->label(),
        ));
    }

    public static function lotBelongsToAnotherArticle(ArticleLot $lot, Article $article): self
    {
        return new self(sprintf(
            'El lote «%s» no es de «%s». Mezclarlos sumaría dos existencias distintas bajo el mismo saldo, y '
            .'el movimiento no fallaría en ningún sitio porque el lote sí existe.',
            $lot->code,
            $article->name,
        ));
    }
}

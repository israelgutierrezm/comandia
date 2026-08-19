<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\Exceptions;

use RuntimeException;

/**
 * La recepción no se puede registrar o aplicar tal como se pidió.
 *
 * Invariantes del documento, no de la captura: dependen del estado de la recepción y del proveedor en el instante de
 * escribir, y el servicio las comprueba con la fila bloqueada.
 */
final class PurchaseReceiptInvariantException extends RuntimeException
{
    public static function inactiveSupplier(string $supplier): self
    {
        return new self(sprintf(
            '«%s» está dado de baja: no se le pueden registrar compras nuevas. Su historial sigue ahí; si le vuelves '
            .'a comprar, reactívalo primero.',
            $supplier,
        ));
    }

    public static function transitWarehouse(): self
    {
        return new self(
            'El almacén de mercancía en tránsito lo escriben sólo las transferencias: no se recibe compra ahí. Lo que '
            .'hay ahí es lo que va en camino.'
        );
    }

    public static function withoutLines(): self
    {
        return new self(
            'Una recepción sin renglones no recibe nada. Captura lo que llegó, o cancélala.'
        );
    }

    public static function notOpen(): self
    {
        return new self(
            'Esta recepción ya se confirmó o se canceló. Si se capturó mal, reversa la recepción: sus movimientos ya '
            .'están en el kardex, que no admite corrección.'
        );
    }

    public static function confirmedCannotBeCancelled(): self
    {
        return new self(
            'Una recepción confirmada no se cancela: ya movió existencia y capturó costo. Se REVERSA, y la reversa '
            .'queda como documento propio enlazado a ésta.'
        );
    }

    public static function onlyConfirmedCanBeReversed(): self
    {
        return new self(
            'Sólo se reversa una recepción confirmada. Un borrador se cancela, porque nunca movió nada.'
        );
    }

    public static function cannotReverseAReversal(): self
    {
        return new self(
            'Una reversa no se reversa. Para volver a meter la mercancía, captura una recepción nueva: así queda con '
            .'el precio y la fecha reales en lugar de copiar los de la compra original.'
        );
    }

    public static function alreadyReversed(string $folio): self
    {
        return new self(sprintf(
            'La recepción %s ya está reversada. Reversarla otra vez sacaría del inventario mercancía que ya salió.',
            $folio,
        ));
    }

    public static function centralWarehouseNeedsBranch(): self
    {
        return new self(
            'No se puede foliar esta recepción: el folio va por sucursal (§7) y ni el almacén ni tu sesión tienen una. '
            .'Elige una sucursal activa antes de recibir en un almacén central.'
        );
    }

    public static function presentationIsNotOfArticle(string $article): self
    {
        return new self(sprintf(
            'La presentación no es de «%s». Con la presentación equivocada, la conversión a unidad base daría una '
            .'cantidad que no corresponde a nada — y ésa es la que entra al inventario.',
            $article,
        ));
    }

    public static function articleIsNotInventoriable(string $article): self
    {
        return new self(sprintf(
            '«%s» no se inventaría, así que recibirlo no puede aumentar ninguna existencia. Márcalo como '
            .'inventariable, o regístralo como gasto en lugar de como compra de mercancía.',
            $article,
        ));
    }

    public static function lotOnArticleWithoutLots(string $article): self
    {
        return new self(sprintf(
            '«%s» no lleva control de lotes, así que capturar un lote no serviría de nada: el sistema no lo usaría al '
            .'surtir. Actívale el control de lotes en el catálogo si lo necesitas.',
            $article,
        ));
    }
}

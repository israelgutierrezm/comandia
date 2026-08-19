<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use RuntimeException;

/**
 * El conteo no admite la operación que se le pidió.
 *
 * Son invariantes del documento, no de la captura, y por eso no viven en el Form Request: dependen del estado del
 * conteo en el instante de la escritura, y comprobarlas al validar dejaría una ventana entre la comprobación y el
 * efecto. El servicio las verifica con la fila bloqueada.
 */
final class StockCountInvariantException extends RuntimeException
{
    public static function notOpen(): self
    {
        return new self(
            'Este conteo ya está cerrado o cancelado y no admite cambios. Para corregirlo, haz otro conteo.'
        );
    }

    public static function alreadyOpenInWarehouse(string $warehouseName): self
    {
        return new self(sprintf(
            'Ya hay un conteo abierto en «%s». Ciérralo o cancélalo antes de empezar otro: dos conteos abiertos '
            .'del mismo almacén aplicarían la misma diferencia dos veces.',
            $warehouseName,
        ));
    }

    public static function nothingToCount(string $warehouseName): self
    {
        return new self(sprintf(
            'No hay nada que contar en «%s»: ningún artículo inventariable tiene existencia registrada ahí. '
            .'Registra una entrada primero, o cuenta artículos concretos.',
            $warehouseName,
        ));
    }
}

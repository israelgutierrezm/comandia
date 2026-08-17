<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support\Exceptions;

use RuntimeException;

/**
 * Se intentó modificar o borrar un registro inmutable por diseño.
 *
 * Tablas inmutables del proyecto (ARQUITECTURA_MAESTRA §7): diario financiero,
 * kardex, historial de precios, historial de costos, bitácora de auditoría, pagos
 * y —desde la Iteración 1— el historial de estados del tenant.
 *
 * La corrección de un registro inmutable se hace con un movimiento de reversa
 * enlazado al original o con un registro nuevo, nunca editando (ADR-004). Un libro
 * que se puede editar no es un libro, es un borrador.
 */
final class ImmutableRecordException extends RuntimeException
{
    public static function cannotUpdate(string $model): self
    {
        return new self(sprintf(
            '%s es inmutable: no admite UPDATE. La corrección se hace con un registro de '
            .'reversa enlazado al original o con un registro nuevo (ARQUITECTURA_MAESTRA §7).',
            class_basename($model),
        ));
    }

    public static function cannotDelete(string $model): self
    {
        return new self(sprintf(
            '%s es inmutable: no admite DELETE. Borrar destruiría justamente la evidencia '
            .'que esta tabla existe para conservar (ARQUITECTURA_MAESTRA §7).',
            class_basename($model),
        ));
    }
}

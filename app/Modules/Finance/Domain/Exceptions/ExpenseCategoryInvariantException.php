<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

/**
 * Se intentó hacer con una categoría de gasto algo que el dominio no admite.
 */
final class ExpenseCategoryInvariantException extends FinanceInvariantException
{
    public static function systemCannotBeDeleted(string $name): self
    {
        return new self(sprintf(
            'La categoría «%s» es del sistema y no se borra: se da de baja. Los gastos ya registrados la citan, y un '
            .'gasto que no puede decir en qué se gastó no sirve para el reporte que justifica su existencia.',
            $name,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Exceptions;

/**
 * Se intentó registrar un gasto que no encaja (§6.5).
 *
 * Hereda de `FinanceInvariantException` para que el traductor a HTTP del módulo la reconozca por su base — la lección
 * del paso 4, donde reusar la excepción de los métodos de pago para las categorías de gasto destapó que una excepción
 * sin registrar responde 500.
 */
final class ExpenseInvariantException extends FinanceInvariantException
{
    public static function membershipRequired(): self
    {
        return new self('No hay una persona en contexto a la que atribuir este gasto.');
    }

    public static function inactiveCategory(string $category): self
    {
        return new self(sprintf(
            'La categoría «%s» está inactiva y no admite gastos nuevos. Los gastos ya registrados con ella siguen ahí: '
            .'desactivarla evita usarla, no borra la historia.',
            $category,
        ));
    }

    public static function notPositive(): self
    {
        return new self('Un gasto de cero no es un gasto, y uno negativo sería un ingreso disfrazado.');
    }

    /**
     * Un gasto de caja necesita turno.
     *
     * Sin él sería dinero que salió de ningún cajón: el arqueo no podría atribuirlo y la diferencia del turno quedaría
     * sin explicación — que es exactamente lo que este registro existe para evitar.
     */
    public static function cashExpenseNeedsSession(): self
    {
        return new self(
            'Un gasto desde caja pertenece a un turno abierto. Sin sesión sería dinero que salió de ningún cajón y el '
            .'arqueo no podría explicarlo.',
        );
    }

    public static function outsideExpenseNeedsMethod(): self
    {
        return new self(
            'Un gasto fuera de caja tiene que decir por dónde se pagó: transferencia, tarjeta de la empresa. Sin método '
            .'no se concilia con nada.',
        );
    }
}

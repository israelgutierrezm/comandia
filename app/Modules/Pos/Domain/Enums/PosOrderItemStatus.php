<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Estado de un item de la cuenta (§6.3).
 *
 * `captured → commanded → preparing → served`, con `cancelled` desde cualquiera.
 *
 * ## La frontera que importa es «comandado»
 *
 * Cancelar un item NO comandado es **borrarlo**: nadie preparó nada y nadie vio el papel, así que no hay hecho que
 * registrar. Cancelar uno comandado exige motivo, PIN y decidir qué se hace con la comida — porque la cocina ya se puso
 * a trabajar.
 */
enum PosOrderItemStatus: string
{
    case Captured = 'captured';
    case Commanded = 'commanded';
    case Preparing = 'preparing';
    case Served = 'served';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Captured => 'Capturado',
            self::Commanded => 'Comandado',
            self::Preparing => 'Preparando',
            self::Served => 'Servido',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * ¿Ya salió a la cocina?
     *
     * Es la pregunta que decide si cancelar es borrar o registrar: a partir de aquí hay alguien trabajando con esa
     * comanda en la mano.
     */
    public function wasCommanded(): bool
    {
        return $this === self::Commanded || $this === self::Preparing || $this === self::Served;
    }

    /**
     * A qué estados puede pasar desde aquí.
     *
     * Lo expone el servidor en `allowed_next`, como en las transferencias de la Iteración 3: el cliente no lleva su
     * propia copia de la máquina de estados. Dos pantallas con dos copias acaban discrepando, y la que discrepa es
     * siempre la que el usuario está mirando.
     *
     * `captured → cancelled` aparece aquí aunque cancelar un item no comandado sea **borrarlo**: desde fuera es la misma
     * acción —«quita esto»— y lo que cambia es si queda rastro. Ver `wasCommanded()`.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Captured => [self::Commanded, self::Cancelled],
            self::Commanded => [self::Preparing, self::Served, self::Cancelled],

            // `preparing → served` y también `preparing → commanded` NO: retroceder un estado que la cocina ya movió
            // sería reescribir lo que pasó. Si se marcó «preparando» por error, se sirve o se cancela con motivo.
            self::Preparing => [self::Served, self::Cancelled],

            // Servido todavía se puede cancelar: el plato llegó mal y se retira. Es justo el caso en que el destino
            // `waste` importa, porque la comida ya no se puede volver a vender.
            self::Served => [self::Cancelled],

            self::Cancelled => [],
        };
    }

    /** ¿Se puede pasar a este estado desde el actual? */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** ¿Cuenta para el total de la cuenta? */
    public function isBillable(): bool
    {
        return $this !== self::Cancelled;
    }
}

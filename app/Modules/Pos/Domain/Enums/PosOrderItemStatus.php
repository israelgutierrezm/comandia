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

    /** ¿Cuenta para el total de la cuenta? */
    public function isBillable(): bool
    {
        return $this !== self::Cancelled;
    }
}

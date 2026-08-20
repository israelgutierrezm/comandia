<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * De dónde salió el dinero (§6.5).
 */
enum ExpenseSource: string
{
    /**
     * Del efectivo de la caja, durante un turno.
     *
     * Es el que hace que el arqueo cuadre o no: el cajero paga los garrafones con dinero del cajón, y un arqueo que no
     * conoce esa salida siempre da corto.
     */
    case CashSession = 'cash_session';

    /**
     * Del negocio, sin pasar por ninguna caja: una transferencia desde la oficina, la tarjeta de la empresa.
     *
     * No toca el arqueo de nadie. Mezclarlo con el anterior haría que el cajero cargara con la renta del local.
     */
    case OutsideCash = 'outside_cash';

    public function label(): string
    {
        return match ($this) {
            self::CashSession => 'Desde caja',
            self::OutsideCash => 'Fuera de caja',
        };
    }

    /** ¿Sale del efectivo de un turno? */
    public function affectsCashDrawer(): bool
    {
        return $this === self::CashSession;
    }
}

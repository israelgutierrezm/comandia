<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Qué se hizo con la cuenta (§4.5).
 */
enum PosAccountOperationKind: string
{
    /** Dividir en partes iguales: reparte el IMPORTE, no los items. */
    case Split = 'split';

    /** Juntar: los items de una cuenta pasan a otra. */
    case Merge = 'merge';

    /** Mover items sueltos entre dos cuentas vivas. */
    case MoveItems = 'move_items';

    /** Volver a abrir una cuenta cerrada. */
    case Reopen = 'reopen';

    public function label(): string
    {
        return match ($this) {
            self::Split => 'División',
            self::Merge => 'Unión',
            self::MoveItems => 'Movimiento de items',
            self::Reopen => 'Reapertura',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

/**
 * Naturaleza de un almacén (D11).
 *
 * Redundante con `branch_id IS NULL` **a propósito**: hace explícito en el modelo
 * lo que si no sería una convención tácita. La contradicción entre los dos la
 * impide un `CHECK` real en la base, porque un almacén central mal marcado surtiría
 * a todas las sucursales sin que nadie lo hubiera decidido.
 */
enum WarehouseKind: string
{
    /** Sin sucursal: surte a todas (ESPECIFICACIÓN_MAESTRA §3). */
    case Central = 'central';

    /** Pertenece a una sucursal concreta. */
    case Branch = 'branch';

    public function requiresBranch(): bool
    {
        return $this === self::Branch;
    }

    public function label(): string
    {
        return match ($this) {
            self::Central => 'Central',
            self::Branch => 'De sucursal',
        };
    }
}

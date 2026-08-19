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

    /**
     * El almacén donde vive la mercancía que va en camino (paso 6).
     *
     * Uno por negocio, creado por el sistema, y **nadie opera con él**: no aparece en selectores, no admite
     * entradas ni salidas manuales y no cuenta como existencia disponible. Sólo lo escriben las transferencias.
     *
     * Existe para que la mercancía en viaje no desaparezca del sistema. Sin él, al enviar 100 y recibir 95 el
     * origen bajaría 100, el destino subiría 95, y ningún movimiento explicaría los 5 que faltan — así que la
     * pérdida no aparecería en el reporte de mermas, que D168 definió como un filtro sobre el kardex.
     *
     * La alternativa era recibir los 100 en destino y mermar 5 ahí, que cuadra pero escribe una mentira en el
     * kardex del destino: una entrada de mercancía que nunca llegó, en la tabla que §7 declara evidencia
     * inmutable.
     */
    case Transit = 'transit';

    public function requiresBranch(): bool
    {
        return $this === self::Branch;
    }

    /** ¿Puede una persona registrar movimientos a mano en él? */
    public function isOperable(): bool
    {
        return $this !== self::Transit;
    }

    public function label(): string
    {
        return match ($this) {
            self::Central => 'Central',
            self::Branch => 'De sucursal',
            self::Transit => 'En tránsito',
        };
    }
}

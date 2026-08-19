<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\Enums;

/**
 * Estados de una recepción de compra (D26, §3.2).
 *
 * ```
 * draft → confirmed
 *      ↘ cancelled
 * ```
 *
 * ## El borrador tiene contenido de verdad
 *
 * Es la factura capturada y todavía sin aplicar: sirve para cuadrar los totales con el papel **antes** de mover
 * inventario y de dejar un costo en un historial inmutable. Es el estado donde se corrigen los dedazos, y el único.
 *
 * ## `cancelled` es sólo para borradores
 *
 * Una recepción confirmada ya movió existencia y capturó costo, así que no se cancela: se **reversa** con otra
 * recepción que la señala. La original no se toca ni para marcarla — igual que en el kardex, la corrección es un
 * registro nuevo y no una edición del viejo.
 */
enum PurchaseReceiptStatus: string
{
    /** Capturada, sin aplicar. No ha movido inventario ni costo. */
    case Draft = 'draft';

    /** Aplicada: los movimientos están en el kardex y el costo en su historial. Inmutable. */
    case Confirmed = 'confirmed';

    /** Se descartó sin aplicar nada. */
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return $this === self::Draft;
    }

    public function isConfirmed(): bool
    {
        return $this === self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Confirmed => 'Confirmada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

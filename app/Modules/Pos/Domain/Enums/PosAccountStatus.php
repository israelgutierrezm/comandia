<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Estado de una cuenta (§6.3).
 *
 * `open → bill_requested → closed → paid`, con `cancelled` como salida sólo mientras no haya pagos.
 */
enum PosAccountStatus: string
{
    case Open = 'open';

    /**
     * Se pidió la cuenta: imprime el ticket de cierre y, si el negocio lo configuró, bloquea la captura de más items.
     *
     * El bloqueo es configurable (`pos.lock_items_on_bill_request`) porque los dos comportamientos son legítimos: en un
     * restaurante, pedir la cuenta significa «ya terminamos»; en un bar, alguien pide la cuenta y a los cinco minutos
     * pide otra cerveza.
     */
    case BillRequested = 'bill_requested';

    /** El total quedó fijado y se está cobrando. */
    case Closed = 'closed';

    case Paid = 'paid';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::BillRequested => 'Cuenta solicitada',
            self::Closed => 'Cerrada',
            self::Paid => 'Pagada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** ¿Sigue viva? Lo que decide si aparece en el piso de venta. */
    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::BillRequested || $this === self::Closed;
    }

    /** ¿Se pueden capturar más items? */
    /**
     * ¿Se le puede aplicar un pago?
     *
     * Desde `open` también, y no sólo desde `closed`: en una barra el cliente paga en cuanto le sirven, sin que nadie
     * «pida la cuenta». Exigir el cierre previo obligaría a dos toques extra en la operación más repetida de un bar.
     *
     * Lo que sí queda fuera es una cuenta ya pagada o cancelada: la primera no debe nada y la segunda no existe. Cobrar
     * de más se corrige con una reversa, no aplicando otro pago encima.
     */
    public function acceptsPayments(): bool
    {
        return $this === self::Open || $this === self::BillRequested || $this === self::Closed;
    }

    public function acceptsItems(): bool
    {
        return $this === self::Open || $this === self::BillRequested;
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::BillRequested, self::Closed, self::Cancelled],

            // Se puede volver a abrir: en un bar, pedir la cuenta y luego otra cerveza es lo normal.
            self::BillRequested => [self::Open, self::Closed, self::Cancelled],

            // Reabrir una cuenta cerrada exige su propio permiso (`pos.accounts.reopen`) y queda auditado.
            self::Closed => [self::Open, self::Paid],

            // Una cuenta pagada NO se cancela: se corrige por reversa de sus pagos. Cancelarla borraría la venta y
            // dejaría los pagos apuntando a algo que el sistema dice que nunca se cobró.
            self::Paid, self::Cancelled => [],
        };
    }
}

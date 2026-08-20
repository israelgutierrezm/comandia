<?php

declare(strict_types=1);

namespace App\Modules\Floor\Domain\Enums;

/**
 * Estado de una mesa (§6.4).
 *
 * ## No es una máquina de estados libre
 *
 * El estado de una mesa lo mueve **lo que pasa con sus cuentas**, no una persona eligiendo de una lista. Se ocupa al
 * abrir una cuenta, pasa a «cuenta solicitada» al pedirla, y se libera cuando **todas** sus cuentas están pagadas
 * (§6.3). Las únicas transiciones que alguien hace a mano son marcar limpia una mesa que espera limpieza y liberar una
 * ocupada por error.
 */
enum TableStatus: string
{
    case Free = 'free';
    case Occupied = 'occupied';
    case BillRequested = 'bill_requested';

    /**
     * Espera limpieza. **Configurable** (§6.4): con `floor.use_cleaning_state` apagado, una mesa pagada vuelve directo
     * a libre.
     *
     * Que sea configurable no es indecisión: en una fonda de comida corrida el mesero limpia y sienta a los siguientes
     * en el mismo movimiento, y un estado intermedio obligatorio sería un toque de más por mesa y por servicio. En un
     * restaurante con encargado de piso, en cambio, es justo la señal que necesita.
     */
    case NeedsCleaning = 'needs_cleaning';

    /**
     * Reservada. **Previsto y no usado** (D33).
     *
     * Las reservaciones quedaron fuera de v1 y el enum se pidió preparado. Está aquí para que agregarlas no exija un
     * `ALTER TABLE ... MODIFY COLUMN` sobre un enum, que bloquea la tabla — y el salón es lo que más se consulta durante
     * el servicio.
     */
    case Reserved = 'reserved';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Libre',
            self::Occupied => 'Ocupada',
            self::BillRequested => 'Cuenta solicitada',
            self::NeedsCleaning => 'Por limpiar',
            self::Reserved => 'Reservada',
        };
    }

    /** ¿Se le puede sentar gente? */
    public function isAvailable(): bool
    {
        return $this === self::Free;
    }

    /** ¿Tiene servicio en curso? */
    public function isBusy(): bool
    {
        return $this === self::Occupied || $this === self::BillRequested;
    }
}

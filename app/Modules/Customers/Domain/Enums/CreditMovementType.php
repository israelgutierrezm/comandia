<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

/**
 * Qué le pasó al saldo de un cliente (§8.3).
 */
enum CreditMovementType: string
{
    /** Se fió un consumo: el cliente debe más. */
    case Charge = 'charge';

    /** El cliente pagó: debe menos. */
    case Repayment = 'repayment';

    /**
     * Una corrección.
     *
     * Existe porque los movimientos son inmutables: si se cargó de más, no se edita el cargo — se registra un ajuste en
     * contra. Va en cualquier dirección, y por eso el monto lleva signo en lugar de deducirse del tipo.
     */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Charge => 'Cargo',
            self::Repayment => 'Abono',
            self::Adjustment => 'Ajuste',
        };
    }

    /**
     * El sentido natural del monto.
     *
     * Un cargo suma a lo que el cliente debe; un abono resta. El ajuste devuelve 0 —«cualquiera de los dos es
     * legítimo»— igual que la diferencia de corte en el diario (D253).
     */
    public function naturalSign(): int
    {
        return match ($this) {
            self::Charge => 1,
            self::Repayment => -1,
            self::Adjustment => 0,
        };
    }
}

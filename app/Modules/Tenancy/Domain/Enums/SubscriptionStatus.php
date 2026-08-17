<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

/**
 * Estado de la suscripción (D4).
 *
 * Deliberadamente pobre: el cobro real se implementa al final del proyecto y la
 * forma comercial exacta no está definida. Lo que la arquitectura necesita hoy es
 * saber si la suscripción está vigente, no modelar un ciclo de facturación que
 * nadie ha decidido.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::PastDue => 'Con adeudo',
            self::Cancelled => 'Cancelada',
        };
    }
}

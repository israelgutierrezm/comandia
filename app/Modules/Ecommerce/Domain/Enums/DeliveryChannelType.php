<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Enums;

/**
 * Los marketplaces de delivery que Comandia integra (ADR-015). `fake` es el adaptador de pruebas —igual
 * que la pasarela de pago `fake`—: existe para ejercitar el pipeline sin credenciales reales.
 */
enum DeliveryChannelType: string
{
    case DidiFood = 'didi_food';
    case UberEats = 'uber_eats';
    case Rappi = 'rappi';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::DidiFood => 'DiDi Food',
            self::UberEats => 'Uber Eats',
            self::Rappi => 'Rappi',
            self::Fake => 'Pruebas',
        };
    }

    /** Un canal REAL (con API externa), a diferencia del adaptador de pruebas. */
    public function isReal(): bool
    {
        return $this !== self::Fake;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Estado de un lote (D23).
 *
 * Tres estados y no dos, porque «ya no hay» y «ya no sirve» son cosas distintas y el negocio actúa distinto
 * con cada una: un lote agotado se acabó vendiendo, y uno caducado se va a merma. Colapsarlos en «inactivo»
 * perdería justo la información que el reporte de mermas necesita.
 */
enum LotStatus: string
{
    /** Se puede surtir de él. */
    case Active = 'active';

    /** Su saldo llegó a cero. Se conserva porque los movimientos que lo citan siguen existiendo. */
    case Depleted = 'depleted';

    /**
     * Pasó su caducidad. NO se agota solo: alguien tiene que registrar la merma, y hasta entonces el saldo
     * sigue ahí — el sistema no decide por su cuenta que la mercancía se tiró.
     */
    case Expired = 'expired';

    public function canBeIssued(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Depleted => 'Agotado',
            self::Expired => 'Caducado',
        };
    }
}

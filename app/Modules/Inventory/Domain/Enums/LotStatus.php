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

    /**
     * Su saldo llegó a cero. Se conserva porque los movimientos que lo citan siguen existiendo.
     *
     * Lo pone el kardex al dar la salida que lo deja en cero, y lo quita la entrada que le devuelve mercancía (D377);
     * también una persona, cuando ya no queda nada aunque los libros digan otra cosa. La salida automática no lo surte.
     * El del kardex se calcula después del commit con todos los saldos confirmados, así que una carrera entre dos
     * almacenes no deja mercancía atorada.
     */
    case Depleted = 'depleted';

    /**
     * Pasó su caducidad. NO se agota solo: alguien tiene que registrar la merma, y hasta entonces el saldo
     * sigue ahí — el sistema no decide por su cuenta que la mercancía se tiró.
     *
     * Lo pone una persona o la tarea de cada noche (`comandia:lots:mark-expired`, D377). Y aunque nadie lo haya puesto,
     * la salida automática ya no surte un lote con la caducidad vencida.
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

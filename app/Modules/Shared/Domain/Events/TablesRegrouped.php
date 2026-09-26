<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se unieron mesas a una principal, o se separaron (D32, §6.4).
 *
 * ## Por qué no basta `TableStateChanged`
 *
 * Unir y separar no cambian el ESTADO de ninguna mesa —una libre unida a otra sigue «libre»—, así que no pasan por
 * `TableOccupancy` y el piso en vivo no se enteraba: las demás terminales seguían ofreciendo sentar gente en la mitad de
 * una mesa de ocho hasta que llegara otro aviso cualquiera (con socket, el sondeo está apagado). Emitir un
 * `TableStateChanged` con el mismo estado de origen y destino habría sido mentirle a sus oyentes; éste dice lo que pasó.
 *
 * Como aquél, NO lleva cuentas ni importes: termina en un canal que oye todo el que atiende.
 */
final readonly class TablesRegrouped implements CrossModuleEvent
{
    use Dispatchable;

    /**
     * @param  list<string>  $tableUlids  las mesas que se unieron a la principal o se soltaron de ella
     */
    public function __construct(
        private int $tenantId,
        public string $branchUlid,
        public string $mainTableUlid,
        public array $tableUlids,
        public bool $joined,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

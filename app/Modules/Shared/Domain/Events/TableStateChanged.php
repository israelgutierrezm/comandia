<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una mesa cambió de estado en el piso (§6.4).
 *
 * ## Por qué no existía hasta ahora
 *
 * Porque nadie escuchaba. `TableOccupancy` escribía `restaurant_tables.status` —ocupa, pide cuenta, libera— y con eso
 * bastaba: quien quería saberlo lo consultaba. Con el piso en vivo es al revés, y este evento es la fuente principal
 * de lo que se dibuja.
 *
 * ## Se emite desde UN solo sitio, y por eso se puede confiar en él
 *
 * `TableOccupancy` es el único punto que escribe ese campo, y lo es desde el paso 7 de la Iteración 4 — donde se
 * centralizó justo porque el POS lo escribía por su cuenta con un comentario que lo justificaba. Ese trabajo es el que
 * hace que hoy exista un lugar donde poner esto: repartido por tres módulos, el piso en vivo se habría perdido
 * transiciones sin que nada fallara.
 *
 * ## Lleva el estado ANTERIOR
 *
 * No es adorno. Una pantalla que recibe «ocupada» sin saber de dónde viene no puede distinguir «acaban de sentarse» de
 * «seguía ocupada y algo más cambió», y son dos avisos distintos para quien coordina el salón. Además hace el evento
 * legible en la bitácora de la cola sin consultar nada.
 *
 * ## Y NO lleva la cuenta ni su importe
 *
 * El ULID de la cuenta sí, porque es lo que permite a la pantalla saber a dónde llevar al que toque la mesa. Nada más:
 * este evento acaba en un canal que oye todo el que atiende, y el dinero se pide por la API, donde el permiso sí se
 * comprueba.
 */
final readonly class TableStateChanged implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        private int $tenantId,
        public string $tableUlid,
        public string $branchUlid,
        public string $from,
        public string $to,
        public ?string $accountUlid = null,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

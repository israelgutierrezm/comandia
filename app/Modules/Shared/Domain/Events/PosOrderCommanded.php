<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Salió una comanda a un área de preparación (§6.3).
 *
 * **Uno por área**, no uno por orden. Una orden de una cuenta puede tocar cocina, barra y postres, y cada área necesita
 * su propio papel en su propia impresora: un evento por orden obligaría a quien escucha a volver a agrupar por área,
 * que es justo el trabajo que `Pos` ya hizo al partir la orden.
 *
 * ## Lleva el ULID del ticket y no sus renglones
 *
 * Quien imprime lee el ticket de la base. Meter los renglones en el evento los duplicaría —y con ellos el riesgo de que
 * el papel diga algo distinto de lo que quedó registrado— además de engordar la carga que se serializa a la cola. El
 * contrato de D231 pide primitivos, no un volcado del documento.
 */
final readonly class PosOrderCommanded implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public string $ticketUlid,
        public int $ticketId,
        public int $branchId,
        public string $accountUlid,
        public string $accountDisplayName,
        public int $orderSequence,
        public ?int $preparationAreaId,
        public int $actorMembershipId,
        public string $issuedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

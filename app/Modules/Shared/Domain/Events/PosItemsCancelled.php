<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se cancelaron items YA COMANDADOS (§6.3).
 *
 * No se emite al cancelar un item que nadie preparó: eso es **borrarlo**, y no hay hecho que anunciar. Este evento
 * existe porque alguien en la cocina tiene una comanda en la mano con algo que ya no va, y porque puede haber comida
 * hecha que se convierte en merma.
 *
 * ## Dos oyentes, dos efectos distintos
 *
 * `Printing` saca la comanda de cancelación al área. `Inventory` registra la merma **sólo si el destino es `waste`**:
 * con `restock` no se tocó el producto y no hay nada que mermar.
 *
 * ## Lleva el destino y la cantidad, no el motivo
 *
 * El motivo queda en la línea y en la bitácora, que es donde se audita. Quien escucha necesita saber **qué hacer** —
 * mermar o no, y cuánto—, no por qué se decidió: darle el motivo invitaría a que alguna vez ramificara por texto libre.
 */
final readonly class PosItemsCancelled implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $accountUlid,
        public string $accountDisplayName,
        public ?int $preparationAreaId,

        /**
         * Los items cancelados, cada uno con lo que hay que hacer con él.
         *
         * @var list<array{item_ulid: string, article_id: int, article_name: string, quantity: numeric-string, destination: string}>
         */
        public array $items,

        public string $cancellationTicketUlid,
        public int $actorMembershipId,
        public string $cancelledAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

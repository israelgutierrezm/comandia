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
 * Uno por área, y también uno por lo que no tiene área —la cerveza que el mesero sacó de la nevera—, que sale **sin
 * comanda de cancelación** (`cancellationTicketUlid` nulo): no hay papel que tachar, pero si se tiró también es merma
 * (D375).
 *
 * ## Dos oyentes, dos efectos distintos
 *
 * `Printing` saca la comanda de cancelación al área, si la hay. `Inventory` registra la merma **sólo si el destino es
 * `waste`**: con `restock` no se tocó el producto, y como la venta nunca lo descontó, no hay nada que mermar ni que
 * devolver.
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

        /** La comanda de cancelación del área; `null` para lo que no tiene área, que no lleva papel. */
        public ?string $cancellationTicketUlid,
        public int $actorMembershipId,
        public string $cancelledAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

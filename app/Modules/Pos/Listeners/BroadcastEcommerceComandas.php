<?php

declare(strict_types=1);

namespace App\Modules\Pos\Listeners;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Shared\Domain\Events\Broadcast\AreaOrderCommanded;
use App\Modules\Shared\Domain\Events\EcommerceOrderAccepted;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Manda a la pantalla de cocina las comandas de un pedido de e-commerce aceptado (Iteración 8, Tanda D parte 2).
 *
 * El espejo e-commerce de {@see BroadcastCommandedOrder}: la misma pantalla de cocina, por el mismo canal de área, con el
 * mismo contrato `AreaOrderCommanded` del kernel — así la cocina trata igual al mostrador y a la tienda. Vive en `Pos`
 * porque `Pos` es dueño de la pantalla de cocina; reacciona al evento del kernel `EcommerceOrderAccepted` sin nombrar a
 * `Ecommerce`, igual que `Finance`/`Inventory` reaccionan a los eventos de e-commerce (ADR-007).
 *
 * No hay aviso al piso: un pedido de e-commerce no está en una mesa. Sin dinero, como toda comanda.
 */
final readonly class BroadcastEcommerceComandas
{
    public function __construct(private TenantContext $tenants) {}

    public function handle(EcommerceOrderAccepted $event): void
    {
        // Puede correr en cola, sin sesión: el contexto se abre con el tenant del evento (D231).
        $this->tenants->runFor($event->tenantId(), function () use ($event): void {
            $tenantUlid = Tenant::query()->whereKey($event->tenantId())->value('ulid');
            $branchUlid = Branch::query()->whereKey($event->branchId)->value('ulid');

            if ($tenantUlid === null || $branchUlid === null) {
                return;
            }

            foreach ($this->byArea($event->items) as $areaId => $lines) {
                $areaUlid = PreparationArea::query()->whereKey($areaId)->value('ulid');

                if ($areaUlid === null) {
                    continue;
                }

                AreaOrderCommanded::dispatch(
                    (string) $tenantUlid,
                    (string) $branchUlid,
                    (string) $areaUlid,
                    $event->orderUlid,
                    1, // un pedido de e-commerce no se fragmenta en secuencias como una cuenta del POS
                    $event->orderFolio,
                    $lines,
                    $event->acceptedAt,
                );
            }
        });
    }

    /**
     * Agrupa las líneas por área; las que no tienen área no se comandan (D240).
     *
     * @param  list<array{article_id: int, name: string, quantity: int, preparation_area_id: int|null}>  $items
     * @return array<int, list<array{name: string, quantity: string, notes: string|null}>>
     */
    private function byArea(array $items): array
    {
        $grouped = [];

        foreach ($items as $line) {
            if ($line['preparation_area_id'] === null) {
                continue;
            }

            $grouped[(int) $line['preparation_area_id']][] = [
                'name' => (string) $line['name'],
                'quantity' => (string) $line['quantity'],
                'notes' => null, // sin modificadores en la tienda v1
            ];
        }

        return $grouped;
    }
}

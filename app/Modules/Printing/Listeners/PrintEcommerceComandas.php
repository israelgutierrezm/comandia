<?php

declare(strict_types=1);

namespace App\Modules\Printing\Listeners;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Printing\Application\QueuePrintJob;
use App\Modules\Printing\Application\RenderTicketPayload;
use App\Modules\Shared\Domain\Events\EcommerceOrderAccepted;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imprime las comandas por área de un pedido de e-commerce aceptado (Iteración 8, Tanda D parte 2).
 *
 * El equivalente e-commerce de {@see QueueTicketsForPrinting::handleCommanded()}: al aceptar, cada área con líneas recibe
 * su comanda por su impresora, reusando `QueuePrintJob` y el MISMO contrato de payload que el POS. Las líneas ya traen su
 * área congelada (vía `AreaRouter`, parte 1); se agrupan por área y se encola una comanda por cada una. `Printing` no
 * conoce a `Ecommerce`: reacciona al evento del kernel, como ya hace con `PosAccountPaid`.
 *
 * Un área sin impresora no encola nada y no revienta —igual que el POS (§6.2)—: la pantalla de cocina lo cubre (parte 2).
 * Un fallo se registra y no se propaga: la aceptación ya ocurrió.
 */
final readonly class PrintEcommerceComandas
{
    public function __construct(
        private TenantContext $tenants,
        private QueuePrintJob $queue,
        private RenderTicketPayload $payloads,
    ) {}

    public function handle(EcommerceOrderAccepted $event): void
    {
        try {
            $this->tenants->runFor($event->tenantId(), function () use ($event): void {
                $businessName = Branch::query()->whereKey($event->branchId)->value('name');

                foreach ($this->byArea($event->items) as $areaId => $items) {
                    $area = PreparationArea::query()->whereKey($areaId)->first();

                    // Sin impresora, no se encola (como el POS); la pantalla de cocina lo cubre.
                    if ($area?->printer_id === null) {
                        continue;
                    }

                    $payload = $this->payloads->forEcommerceComanda(
                        $businessName === null ? null : (string) $businessName,
                        $event->orderFolio,
                        (string) $area->name,
                        $items,
                        $event->acceptedAt,
                    );

                    $this->queue->forEcommerceComanda((int) $event->branchId, (int) $area->printer_id, $payload);
                }
            });
        } catch (Throwable $e) {
            Log::error('No se pudo encolar la comanda de un pedido de e-commerce.', [
                'tenant_id' => $event->tenantId(),
                'order' => $event->orderUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Agrupa las líneas por área de preparación; las que no tienen área no se comandan (D240).
     *
     * @param  list<array{article_id: int, name: string, quantity: int, preparation_area_id: int|null}>  $items
     * @return array<int, list<array{name: string, quantity: string}>>
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
            ];
        }

        return $grouped;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Shared\Domain\Events\EcommerceOrderPaid;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario la venta de un pedido de e-commerce pagado (Iteración 8, Tanda C, ADR-007).
 *
 * El equivalente e-commerce de {@see RecordAccountPayments}. La venta es un asiento **automático**: sin actor de personal
 * (el cliente la originó) y sin sesión de caja (el pago fue por pasarela, no por el cajón). Por eso se asienta con el tipo
 * propio `OnlineSale` (ADR-010) y no con `Sale`: una `Sale` exige sesión (§6.3), y aquí no la hay; el tipo propio deja ese
 * candado del POS intacto y hace del canal una consulta por tipo. Idempotente por (documento, tipo): re-despachar el
 * evento no duplica la venta.
 *
 * Simplificación v1 declarada: se asienta la VENTA (productos); el pago por pasarela no se journaliza como movimiento de
 * caja —no hay corte que cuadrar en línea— y el envío no se separa como ingreso. Ambos son evolución.
 *
 * No puede tumbar el pago: corre después del commit del webhook (D220); un fallo se registra y no se propaga.
 */
final readonly class RecordEcommerceOrderSale
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handle(EcommerceOrderPaid $event): void
    {
        if (Decimal::round($event->subtotal, 2) === '0.00') {
            return;
        }

        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::OnlineSale,
                    amount: $event->subtotal,
                    sourceType: 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Order',
                    sourceUlid: $event->orderUlid,
                    actorMembershipId: null, // asiento automático: sin actor de personal
                    occurredAt: CarbonImmutable::parse($event->paidAt),
                );
            });
        } catch (Throwable $e) {
            Log::error('No se pudo asentar la venta de e-commerce en el diario.', [
                'tenant_id' => $event->tenantId,
                'order' => $event->orderUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

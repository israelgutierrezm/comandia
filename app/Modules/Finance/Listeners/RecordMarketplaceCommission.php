<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Shared\Domain\Events\MarketplaceCommissionCharged;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario la comisión que un marketplace retuvo de un pedido (ADR-015).
 *
 * Netea la venta en línea (que se asienta al bruto en {@see RecordEcommerceOrderSale}): asiento automático
 * (sin actor de personal) y sin caja (lo liquida la plataforma). El monto va CON SIGNO —el diario lo
 * exige y `MarketplaceCommission` resta—, así que la magnitud positiva del evento se registra en negativo.
 * Idempotente por (documento, tipo): el pedido ya tiene su `OnlineSale`; ésta es otra fila, de otro tipo.
 *
 * No puede tumbar la ingesta: corre después del commit (D220); un fallo se registra y no se propaga.
 */
final readonly class RecordMarketplaceCommission
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handle(MarketplaceCommissionCharged $event): void
    {
        if (Decimal::round($event->commission, 2) === '0.00') {
            return;
        }

        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::MarketplaceCommission,
                    // El diario pide el monto CON signo; la comisión resta, así que la magnitud va en negativo.
                    amount: bcmul(Decimal::round($event->commission, 2), '-1', 2),
                    sourceType: 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Order',
                    sourceUlid: $event->orderUlid,
                    actorMembershipId: null, // asiento automático: sin actor de personal
                    occurredAt: CarbonImmutable::parse($event->occurredAt),
                );
            });
        } catch (Throwable $e) {
            Log::error('No se pudo asentar la comisión de marketplace en el diario.', [
                'tenant_id' => $event->tenantId,
                'order' => $event->orderUlid,
                'channel' => $event->channel,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Shared\Domain\Events\EcommerceOrderRefunded;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reversa en el diario la venta de un pedido de e-commerce reembolsado (Iteración 8, Tanda D, ADR-010 regla 4).
 *
 * El reverso de {@see RecordEcommerceOrderSale}: asienta un `OnlineSale` con el **signo contrario**, enlazado al asiento
 * original por `reverses_movement_id`. Conservar el tipo (y no inventar un tipo «reversa») es lo que hace que «cuánto vendí
 * en línea» —que suma por tipo— descuente los reembolsos solo. El documento origen de la reversa es el **pago de
 * reembolso**, no el pedido: el diario es idempotente por (documento, tipo) y la venta ya ocupó (pedido, `OnlineSale`).
 *
 * Sin actor (asiento automático) y sin sesión, como la venta. No puede tumbar el rechazo: corre tras el commit; un fallo
 * se registra y no se propaga.
 */
final readonly class RefundEcommerceOrderSale
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handle(EcommerceOrderRefunded $event): void
    {
        if (Decimal::round($event->subtotal, 2) === '0.00') {
            return;
        }

        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $original = FinancialMovement::query()
                    ->where('source_type', 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Order')
                    ->where('source_ulid', $event->orderUlid)
                    ->where('type', FinancialMovementType::OnlineSale)
                    ->first();

                // Sin venta que reversar (p. ej. una venta de cero que nunca se asentó), no hay nada que hacer.
                if ($original === null) {
                    return;
                }

                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::OnlineSale,
                    amount: '-'.Decimal::round($event->subtotal, 2), // reversa: signo contrario a la venta
                    sourceType: 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Payment',
                    sourceUlid: $event->refundPaymentUlid,
                    actorMembershipId: null,
                    reverses: $original,
                    occurredAt: CarbonImmutable::parse($event->refundedAt),
                );
            });
        } catch (Throwable $e) {
            Log::error('No se pudo reversar en el diario la venta de un pedido de e-commerce reembolsado.', [
                'tenant_id' => $event->tenantId,
                'order' => $event->orderUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}

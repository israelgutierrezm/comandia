<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un marketplace (DiDi/Uber/Rappi) retuvo su comisión de un pedido (ADR-015).
 *
 * Vive en el kernel como {@see EcommerceOrderPaid}: lo escucha `Finance` para netear la venta en línea, y
 * `Ecommerce` no conoce a `Finance`. Lleva la comisión como magnitud POSITIVA; el oyente le pone el signo
 * (el diario exige el monto con signo, y `MarketplaceCommission` resta). Idempotente en el diario por
 * (documento, tipo): re-emitir no la duplica.
 */
final readonly class MarketplaceCommissionCharged implements CrossModuleEvent
{
    use Dispatchable;

    /**
     * @param  numeric-string  $commission  magnitud positiva de la comisión (IVA incluido)
     */
    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $orderUlid,
        public string $channel,
        public string $commission,
        public string $occurredAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

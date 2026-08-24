<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un pedido de la tienda en línea fue rechazado y reembolsado (Iteración 8, Tanda D parte 2 del diseño, D2).
 *
 * El reverso financiero de {@see EcommerceOrderPaid}: `Finance` asienta la **reversa de la venta en línea** en el diario
 * (mismo tipo `OnlineSale`, signo contrario, enlazada al asiento original — ADR-010 regla 4). No hay reversa de inventario:
 * sólo se rechaza un pedido **pagado y no aceptado**, y el inventario se descuenta al aceptar (D338), así que nunca se movió.
 *
 * Lleva el ULID del **pago de reembolso** como origen del asiento de reversa: el diario es idempotente por (documento,
 * tipo), y la venta ya ocupó (pedido, `OnlineSale`); la reversa necesita su propio documento, y ése es el reembolso.
 */
final readonly class EcommerceOrderRefunded implements CrossModuleEvent
{
    use Dispatchable;

    /**
     * @param  numeric-string  $subtotal  el monto de la venta que se reversa (IVA incluido)
     */
    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $orderUlid,
        public string $refundPaymentUlid,
        public string $subtotal,
        public string $refundedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

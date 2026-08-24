<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un pedido de la tienda en línea quedó pagado (Iteración 8, Tanda C, §6.8, ADR-007).
 *
 * El equivalente e-commerce de {@see PosAccountPaid}, y vive en el kernel por lo mismo: lo escuchan varios módulos y
 * ninguno debe conocer a `Ecommerce`. `Finance` asienta la venta en el diario (canal e-commerce, sin caja) e `Inventory`
 * descuenta los insumos. El pedido no escribe en finanzas ni en el kardex: emite el hecho y ellos reaccionan (ADR-004).
 *
 * Lleva los primitivos que los oyentes necesitan (D231): totales para el asiento y las líneas para el descuento. Ningún
 * oyente puede tumbar el pago —corre después del commit del webhook, con el dinero ya cobrado— (D220).
 */
final readonly class EcommerceOrderPaid implements CrossModuleEvent
{
    use Dispatchable;

    /**
     * @param  numeric-string  $subtotal  productos, IVA incluido (D30)
     * @param  numeric-string  $total  productos + envío
     * @param  numeric-string  $shippingCost
     * @param  list<array{article_id: int, quantity: int}>  $items  para el descuento de inventario
     */
    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $orderUlid,
        public int $customerId,
        public string $subtotal,
        public string $total,
        public string $shippingCost,
        public array $items,
        public string $paidAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}

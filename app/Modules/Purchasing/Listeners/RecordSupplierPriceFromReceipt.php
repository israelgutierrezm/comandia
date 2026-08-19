<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Modules\Purchasing\Application\RecordSupplierPrice;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceiptLine;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;

/**
 * Deja la observación de precio de cada renglón recibido (§3.3, D26).
 *
 * §3.3 lo dice sin rodeos: el historial se alimenta **automáticamente** de cada recepción confirmada, porque
 * «capturarlo a mano sería un catálogo que nadie mantiene». Y con `source = 'receipt'`, que es el único origen que el
 * paso 8 declaró y dejó sin llamador — a propósito, esperando este momento.
 *
 * Éste sí vive en `Purchasing`: la tabla es suya. Es un oyente y no una llamada dentro del servicio de confirmación
 * por una razón más modesta que la frontera de módulos — que los tres efectos de confirmar se lean en el mismo sitio, la
 * lista de oyentes del evento, en lugar de dos ahí y uno escondido dentro de una transacción.
 *
 * ## El precio es SIN IVA, sin importar el criterio de acreditamiento
 *
 * Aquí sí hay una diferencia con el costo: el costo puede incluir el impuesto cuando no es acreditable, porque entonces
 * es dinero que no vuelve. El **precio del proveedor** es siempre el neto, porque la pregunta que el historial contesta
 * es «¿me subió el precio?» y la tasa de IVA la fija la ley, no el proveedor. Una reforma fiscal aparecería como una
 * subida de todos los proveedores a la vez, que es exactamente lo que el reporte no debe decir.
 *
 * ## La reversa no deja observación
 *
 * Una devolución no es un precio observado. Y borrar la observación de la recepción original tampoco: el historial es
 * inmutable, y aquel martes el proveedor sí cotizó a ese precio — aunque la mercancía se haya devuelto después.
 */
final class RecordSupplierPriceFromReceipt
{
    public function __construct(
        private readonly RecordSupplierPrice $prices,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(PurchaseReceiptConfirmed $event): void
    {
        $receipt = $event->receipt;

        if ($receipt->isReversal()) {
            return;
        }

        $this->tenants->runFor($receipt->tenant_id, function () use ($receipt): void {
            $supplier = $receipt->supplier;

            foreach ($receipt->lines()->with(['article', 'presentation'])->get() as $line) {
                $this->prices->forBaseUnit(
                    supplier: $supplier,
                    article: $line->article,

                    // El neto por unidad base: es lo que hace comparables dos proveedores que venden en presentaciones
                    // distintas (D203). Se calcula del subtotal del renglón, nunca del total con impuesto.
                    unitPrice: $this->netPricePerBaseUnit($line),

                    source: SupplierPriceSource::Receipt,
                    observedAt: $receipt->received_at,
                    notes: sprintf('Recepción %s', $receipt->folioNumber()),
                    purchaseReceiptId: $receipt->id,
                );
            }
        });
    }

    /**
     * @return numeric-string
     */
    private function netPricePerBaseUnit(PurchaseReceiptLine $line): string
    {
        return Decimal::divide($line->line_subtotal, $line->quantity_in_base_unit, 4);
    }
}

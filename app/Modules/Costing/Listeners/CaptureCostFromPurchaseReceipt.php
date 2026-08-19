<?php

declare(strict_types=1);

namespace App\Modules\Costing\Listeners;

use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Shared\Domain\Tenancy\TenantContext;

/**
 * Captura el costo de lo comprado cuando se confirma una recepción (§3.2, §4).
 *
 * **Aquí se estrena `CostOrigin::Purchase`**, el valor del enum que existe desde la Iteración 2 y llevaba una iteración
 * entera sin un solo llamador. Era el punto de conexión que el diseño anunciaba: hasta ahora todo costo era manual, y
 * desde aquí el costo llega solo con la factura.
 *
 * ## Por qué es un oyente y no una llamada de `Purchasing`
 *
 * Porque `Costing` es dueño del historial de costos, y `Purchasing` no puede escribir en él (ADR-001). El evento dice
 * qué pasó; cada módulo decide qué hacer con eso. La consecuencia práctica es que el día que el costo deba capturarse
 * distinto —promedio ponderado, por ejemplo (D152 lo dejó como reporte)— se cambia aquí y `Purchasing` no se entera.
 *
 * ## La reversa NO devuelve el costo anterior
 *
 * Y es deliberado. El historial de costos es inmutable: reversar una recepción no borra el costo que capturó, porque
 * durante el tiempo que estuvo vigente **se valuaron movimientos con él** — ventas, mermas, producciones. Borrarlo
 * volvería inexplicables esas valuaciones.
 *
 * Lo que sí ocurre es que la reversa no captura un costo nuevo: no hay compra que fije precio. Si el costo capturado por
 * error hay que corregirlo, se captura uno manual — que es un hecho distinto y honesto: «alguien decidió que el costo es
 * otro», con su actor y su fecha.
 *
 * ## Idempotente por línea
 *
 * Misma llave que el movimiento de inventario, con otro prefijo. Volver a despachar el evento no duplica el costo, que
 * en un historial inmutable sería imposible de limpiar.
 */
final class CaptureCostFromPurchaseReceipt
{
    public function __construct(
        private readonly CaptureArticleCost $costs,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(PurchaseReceiptConfirmed $event): void
    {
        $receipt = $event->receipt;

        // Una reversa no fija precio: la mercancía se fue, no llegó. Capturar un costo aquí diría que el proveedor
        // vendió a ese precio otra vez, en la fecha de la devolución.
        if ($receipt->isReversal()) {
            return;
        }

        $this->tenants->runFor($receipt->tenant_id, function () use ($receipt): void {
            $creditable = (bool) $receipt->vat_was_creditable;

            foreach ($receipt->lines()->with('article')->get() as $line) {
                $this->costs->atUnitCost(
                    article: $line->article,

                    // El costo por unidad BASE, no el precio de la caja. El documento ya sabe repartirlo, y con el
                    // criterio de IVA congelado en la propia recepción.
                    unitCost: $line->costPerBaseUnit($creditable),

                    origin: CostOrigin::Purchase,

                    // La fecha en que la mercancía llegó, no la de captura: el historial de costos tiene que decir
                    // cuándo el costo fue cierto, y una factura se teclea tarde.
                    effectiveAt: $receipt->received_at?->startOfDay(),

                    notes: sprintf('Recepción %s', $receipt->folioNumber()),
                    actorMembershipId: $receipt->confirmed_by_membership_id,

                    // Misma llave que el movimiento de kardex, con otro prefijo: reintentar el evento no duplica un
                    // costo, que en un historial inmutable sería imposible de limpiar.
                    idempotencyKey: "purchase_receipt_cost:{$receipt->id}:line:{$line->id}",
                );
            }
        });
    }
}

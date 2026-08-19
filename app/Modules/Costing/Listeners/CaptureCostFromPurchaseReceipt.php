<?php

declare(strict_types=1);

namespace App\Modules\Costing\Listeners;

use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

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

                    // El instante en que el costo pasó a ser cierto, y NO la medianoche del día de recepción.
                    //
                    // La primera versión usaba `startOfDay()`, y eso producía un artefacto que encontré valuando
                    // existencias en el navegador: `received_at` es una FECHA a propósito —una recepción es de un día
                    // (§3.2)— así que la medianoche hacía que la compra perdiera contra **cualquier** costo capturado
                    // más tarde ese mismo día, incluidos los capturados ANTES de que la mercancía llegara.
                    //
                    // Eso no era una política de precedencia: era la precisión de la columna decidiendo por su cuenta.
                    // El proyecto tiene la regla correcta —una captura retroactiva no pisa el costo vigente— y aquí se
                    // estaba disparando por accidente.
                    //
                    // Ahora es el instante de la confirmación, topado para que nunca caiga después del día en que la
                    // mercancía llegó: confirmar hoy una recepción de hoy sella ahora; confirmar hoy una de la semana
                    // pasada sigue siendo retroactivo y no pisa nada, que es lo correcto.
                    effectiveAt: $this->effectiveMoment($receipt),

                    notes: sprintf('Recepción %s', $receipt->folioNumber()),
                    actorMembershipId: $receipt->confirmed_by_membership_id,

                    // Misma llave que el movimiento de kardex, con otro prefijo: reintentar el evento no duplica un
                    // costo, que en un historial inmutable sería imposible de limpiar.
                    idempotencyKey: "purchase_receipt_cost:{$receipt->id}:line:{$line->id}",
                );
            }
        });
    }

    /**
     * Cuándo pasó a ser cierto el costo de esta recepción.
     *
     * El instante de la confirmación, sin pasar del final del día en que llegó la mercancía. Las dos mitades importan:
     * usar sólo la fecha de recepción sella a medianoche y pierde contra lo capturado ese día; usar sólo `now()` sellaría
     * una recepción de la semana pasada como si el costo fuera de hoy, y pisaría el vigente que sí es más reciente.
     */
    private function effectiveMoment(PurchaseReceipt $receipt): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        $finDelDiaDeRecepcion = $receipt->received_at?->endOfDay();

        if ($finDelDiaDeRecepcion === null) {
            return $now;
        }

        return $now->lessThan($finDelDiaDeRecepcion) ? $now : CarbonImmutable::instance($finDelDiaDeRecepcion);
    }
}

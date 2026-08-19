<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceiptLine;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Registra en el kardex la mercancía de una recepción confirmada (§3.2, §4).
 *
 * Vive en `Inventory` y no en `Purchasing` porque es `Inventory` quien es dueño del kardex: `Purchasing` emite el hecho
 * —«se confirmó una recepción»— y cada módulo aplica lo que le toca. Es la regla de fronteras de ADR-001, la misma por
 * la que el POS jamás escribe en finanzas.
 *
 * ## Los lotes se crean AQUÍ, no al capturar el borrador
 *
 * La línea guarda el lote como texto —tal como viene escrito en la caja— y el `article_lots` nace al confirmar. Un
 * borrador que ya hubiera creado lotes dejaría **lotes huérfanos** si nunca se confirma, y un lote huérfano aparece en
 * el selector de FEFO como si tuviera mercancía por surtir.
 *
 * Se reusa el lote si ya existe con el mismo código: la misma partida puede llegar en dos facturas, y crear dos lotes
 * con el mismo código repartiría su existencia entre dos saldos y FEFO surtiría del equivocado.
 *
 * ## Idempotente por línea
 *
 * Llave `purchase_receipt:{id}:line:{id}`, así que volver a despachar el evento —porque otro oyente falló y se
 * reintentó— no duplica ni un gramo. Es la condición que hace seguro el reintento del que habla el evento.
 */
final class RegisterStockFromPurchaseReceipt
{
    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(PurchaseReceiptConfirmed $event): void
    {
        $receipt = $event->receipt;

        // El contexto de negocio se fija explícitamente: un oyente puede correr desde una cola, donde no hay sesión ni
        // petición, y sin esto los global scopes no sabrían de qué negocio leer.
        $this->tenants->runFor($receipt->tenant_id, function () use ($receipt): void {
            $lines = $receipt->lines()->with('article')->get();

            foreach ($lines as $line) {
                DB::transaction(fn () => $this->applyLine($receipt, $line));
            }
        });
    }

    private function applyLine(PurchaseReceipt $receipt, PurchaseReceiptLine $line): void
    {
        // Una reversa saca del lote que creó la recepción original, que ya viene resuelto en la línea. Una recepción
        // normal crea o reusa el lote según el código capturado.
        // Se busca por id en lugar de por relación porque la línea NO declara una: eso obligaría a `Purchasing` a
        // importar los modelos de `Inventory` y el grafo de módulos quedaría con un ciclo. El módulo dueño del lote
        // —éste— es el que lo resuelve.
        $lot = $line->lot_id !== null
            ? ArticleLot::query()->find($line->lot_id)
            : $this->resolveLot($line);

        $isReversal = $receipt->isReversal();

        $movement = $this->movements->record(
            warehouse: $receipt->warehouse,
            article: $line->article,

            // La reversa SALE, y con su tipo propio: `purchase_return` dice que la mercancía volvió al proveedor. Un
            // `manual_adjustment` diría «salió algo y nadie sabe por qué» (D157), que aquí sería falso.
            kind: $isReversal ? StockMovementKind::PurchaseReturn : StockMovementKind::PurchaseReceipt,

            quantity: $line->quantity_in_base_unit,
            lot: $lot,

            // El costo lo trae el documento y no se relee del artículo: es lo que distingue una recepción de cualquier
            // otro movimiento (`carriesOwnCost`). La recepción ES el hecho que fija el costo, así que tomarlo del
            // costo vigente sería valuar la compra con el precio de la compra anterior.
            unitCost: $line->costPerBaseUnit((bool) $receipt->vat_was_creditable),

            source: $receipt,

            // Por línea. Volver a despachar el evento no duplica ni un gramo.
            idempotencyKey: "purchase_receipt:{$receipt->id}:line:{$line->id}",

            occurredAt: $receipt->received_at?->startOfDay(),
            notes: sprintf('%s %s', $isReversal ? 'Devolución' : 'Recepción', $receipt->folioNumber()),
        );

        // El enlace de vuelta: hace navegable recepción → kardex y vuelve DETECTABLE una confirmación a medias — una
        // línea con cantidad y sin movimiento es una confirmación que se interrumpió.
        $line->update([
            'lot_id' => $lot?->id,
            'movement_id' => $movement->id,
        ]);
    }

    /**
     * El lote de la línea: el que ya existe con ese código, o uno nuevo.
     *
     * `null` cuando la línea no capturó lote o el artículo no los lleva. Crear un lote para un artículo sin control de
     * lotes no serviría de nada —el sistema no lo usaría al surtir— y ensuciaría el catálogo.
     */
    private function resolveLot(PurchaseReceiptLine $line): ?ArticleLot
    {
        if ($line->lot_code === null || ! $line->article->tracksLots()) {
            return null;
        }

        $existing = ArticleLot::query()
            ->where('article_id', $line->article->id)
            ->where('code', $line->lot_code)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ArticleLot::create([
            'article_id' => $line->article->id,
            'code' => $line->lot_code,
            'expires_at' => $line->expires_at,

            // La fecha de recepción de la partida es la de la recepción, no la de hoy: importa para el orden de FEFO
            // cuando dos lotes no tienen caducidad.
            'received_at' => $line->receipt->received_at,
            'status' => LotStatus::Active,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\IssueStock;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Http\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Http\Requests\StoreStockEntryRequest;
use App\Modules\Inventory\Http\Requests\StoreStockExitRequest;
use App\Modules\Inventory\Http\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Entradas, salidas y ajustes manuales de inventario.
 *
 * ## Tres endpoints y no uno con un campo `kind`
 *
 * El catálogo cerrado distingue `inventory.entries.create`, `inventory.exits.create` y
 * `inventory.adjustments.create` (D10). Con un endpoint único no habría forma de exigir el permiso correcto:
 * `can:` recibe **un** permiso, y decidirlo leyendo el cuerpo dejaría la ruta sin permiso declarado — invisible
 * para el candado de D129, que es el que garantiza que ningún endpoint quede abierto.
 *
 * Y hay una segunda razón, más importante: un `kind` libre en el cuerpo permitiría registrar a mano un
 * `sale_consumption` o un `transfer_out`, y esos **pertenecen a un documento**. Un consumo por venta sin su
 * cuenta como origen es un movimiento que nadie puede explicar después.
 *
 * ## Lo que NO se registra aquí
 *
 * Mermas (tienen catálogo de motivos y umbral de autorización, D27), ajustes de conteo (los genera el cierre
 * del conteo, D24), transferencias, producción y consumo por venta. Todos llegan en pasos posteriores con su
 * documento, que es su explicación.
 */
final class StockMovementController
{
    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly IssueStock $issues,
        private readonly ContextHolder $context,
    ) {}

    public function storeEntry(StoreStockEntryRequest $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * Una salida puede ser VARIOS movimientos, así que devuelve una lista.
     *
     * Cuando el artículo lleva lotes y el más próximo a caducar no alcanza, la salida se parte: 300 ml del lote
     * de marzo y 200 del de abril son dos renglones del kardex. Cada uno dice de qué partida física salió, que
     * es exactamente lo que hace falta para rastrear un lote defectuoso.
     *
     * La respuesta es una lista **siempre**, incluso cuando hay un solo movimiento: una forma que cambia según
     * cuántos lotes había obligaría al cliente a manejar los dos casos, y el día que un artículo empiece a
     * llevar lotes su integración se rompería sin avisar.
     */
    public function storeExit(StoreStockExitRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();
        $article = Article::query()->where('ulid', $request->string('article_ulid'))->sole();

        $this->assertWarehouseInScope($warehouse);

        $movements = $this->issues->issue(
            warehouse: $warehouse,
            article: $article,
            kind: $request->movementKind(),
            quantity: $request->string('quantity')->toString(),

            // Un lote explícito gana a FEFO: quien lo indica está mirando la caja física.
            lot: $request->filled('lot_ulid')
                ? ArticleLot::query()->where('ulid', $request->string('lot_ulid'))->sole()
                : null,

            occurredAt: $request->filled('occurred_at')
                ? CarbonImmutable::parse($request->string('occurred_at')->toString())
                : null,

            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        $loaded = collect($movements)->each(
            fn ($movement) => $movement->load(['article.baseUnit', 'warehouse', 'lot', 'actor'])
        );

        return StockMovementResource::collection($loaded)
            ->response()
            ->setStatusCode(201);
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * El registro, común a los tres. El tipo lo declara el Form Request de cada endpoint.
     */
    private function store(StoreStockMovementRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();
        $article = Article::query()->where('ulid', $request->string('article_ulid'))->sole();

        $this->assertWarehouseInScope($warehouse);

        $lot = $request->filled('lot_ulid')
            ? ArticleLot::query()->where('ulid', $request->string('lot_ulid'))->sole()
            : null;

        $movement = $this->movements->record(
            warehouse: $warehouse,
            article: $article,
            kind: $request->movementKind(),
            quantity: $request->string('quantity')->toString(),

            // Sólo los ajustes la mandan; en los otros dos el Form Request la prohíbe y el tipo la decide.
            direction: $request->filled('direction')
                ? StockMovementDirection::from($request->string('direction')->toString())
                : null,

            lot: $lot,

            // Si no viene, el servicio valúa al costo vigente del artículo (D152).
            unitCost: $request->filled('unit_cost') ? $request->string('unit_cost')->toString() : null,

            occurredAt: $request->filled('occurred_at')
                ? CarbonImmutable::parse($request->string('occurred_at')->toString())
                : null,

            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return (new StockMovementResource($movement->load(['article.baseUnit', 'warehouse', 'lot', 'actor'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * El almacén tiene que estar al alcance de quien opera.
     *
     * Mismo hueco que cierra `assertBranchInScope` en el catálogo: el `tenant_id` protege del negocio ajeno,
     * **no** de la sucursal ajena dentro del propio. Sin esto, un almacenista con alcance sobre una sucursal
     * podría mover existencias de otra, y el movimiento quedaría firmado con su nombre en un almacén al que no
     * tiene acceso.
     *
     * Un almacén **central** no pertenece a ninguna sucursal: surte a todas (D11), así que no hay alcance que
     * comprobar. Exigir una sucursal ahí dejaría el almacén central inoperable para todos.
     */
    private function assertWarehouseInScope(Warehouse $warehouse): void
    {
        if ($warehouse->branch_id === null) {
            return;
        }

        $membership = $this->context->getOrNull()?->membership;

        if ($membership === null || ! $membership->canOperateInBranch($warehouse->branch_id)) {
            throw new HttpException(403, 'No tienes acceso al almacén de esa sucursal.');
        }
    }
}

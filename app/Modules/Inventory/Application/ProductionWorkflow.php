<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\ProductionOrderStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Exceptions\ProductionInvariantException;
use App\Modules\Inventory\Domain\ProductionConsumption;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrderLine;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planear y completar una producción (D17, P8).
 *
 * ## Planear no mueve nada; completar mueve todo de golpe
 *
 * Una orden en borrador es una intención: «mañana hacemos veinte litros de salsa». No reserva insumos —en v1 no hay
 * reservas— y no congela la receta, porque la receta que vale es la que esté en vigor cuando de verdad se produzca.
 *
 * Al completar se escriben **N + 1 movimientos**: una salida por cada insumo (partida por FEFO si lleva lotes) y una
 * entrada del producible, todas con la orden como documento origen. Y las líneas de la orden se escriben en ese mismo
 * momento: son el snapshot real de la receta usada, que la llave a `recipes` no puede dar porque esa tabla es mutable.
 *
 * ## La producción NO se bloquea por falta de insumos
 *
 * Es la misma regla de §6.2 que impide bloquear el POS, y por el mismo motivo: la cocina hizo la salsa —está ahí, en
 * la olla— independientemente de lo que el sistema creyera tener. Bloquear no impediría la producción, sólo impediría
 * *registrarla*, y el resultado sería un inventario que se descuadra sin dejar rastro. El saldo negativo es la señal
 * de que el conteo va atrasado.
 *
 * ## La valuación la decide el kardex, no este servicio
 *
 * No se pasa `unitCost` a ningún movimiento: cada uno se valúa por la puerta única del kardex, con el costo vigente
 * del artículo (D152). Podría pasarse el costo recursivo que calcula el motor de costeo, y sería **peor**: el mismo
 * componente quedaría valuado de una forma en sus salidas por producción y de otra en todas sus demás salidas, y dos
 * valuaciones del mismo artículo son dos verdades.
 *
 * Lo que sí se congela en el documento es el resultado: el costo unitario con el que entró el producible y el de cada
 * insumo que salió. Así la orden se explica sola dentro de un año, cuando los costos ya cambiaron.
 */
final class ProductionWorkflow
{
    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly IssueStock $issues,
        private readonly ResolveProductionConsumption $consumption,
        private readonly ResolveArticleCost $costs,
        private readonly ContextHolder $context,
    ) {}

    /**
     * Planea una producción. No mueve inventario.
     *
     * La receta se valida ya —producible, con receta activa, con ingredientes inventariables— aunque no se congele:
     * dejar planear algo imposible de producir sólo aplaza el error hasta el momento de más prisa.
     *
     * @param  numeric-string  $plannedQuantity
     *
     * @throws ProductionInvariantException
     */
    public function plan(
        Warehouse $warehouse,
        Article $article,
        string $plannedQuantity,
        ?string $notes = null,
    ): ProductionOrder {
        $recipe = $this->consumption->activeRecipeOf($article);

        // Se resuelve el consumo y se descarta: la llamada es la validación. Un componente no inventariable o un
        // rendimiento de cero se descubren aquí y no cuando alguien tenga la olla en la mano.
        $this->consumption->forQuantity($article, $plannedQuantity);

        $order = ProductionOrder::create([
            'warehouse_id' => $warehouse->id,
            'article_id' => $article->id,
            'recipe_id' => $recipe->id,
            'status' => ProductionOrderStatus::Draft,
            'planned_quantity' => $plannedQuantity,
            'created_by_membership_id' => $this->requireMembership(),
            'notes' => $notes,
        ]);

        return $order->refresh();
    }

    /**
     * Completa la producción: consume los insumos y genera el producible.
     *
     * @param  numeric-string|null  $producedQuantity  lo que de verdad salió; por omisión, lo planeado
     *
     * @throws ProductionInvariantException
     */
    public function complete(ProductionOrder $order, ?string $producedQuantity = null): ProductionOrder
    {
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use ($order, $producedQuantity, $membershipId): ProductionOrder {
            $locked = ProductionOrder::query()->lockForUpdate()->whereKey($order->id)->sole();

            if (! $locked->isOpen()) {
                throw ProductionInvariantException::notOpen();
            }

            /** @var numeric-string $quantity */
            $quantity = $producedQuantity ?? $locked->planned_quantity;

            $article = $locked->article;
            $warehouse = $locked->warehouse;

            // La receta se lee AHORA, no al planear: el hecho físico es éste, y la receta que lo explica es la que
            // estaba en vigor cuando ocurrió.
            $consumptions = $this->consumption->forQuantity($article, $quantity);

            foreach ($consumptions as $index => $consumption) {
                $this->consume($locked, $consumption, $warehouse, $index);
            }

            // La entrada del producible va DESPUÉS de las salidas, y el orden importa para leer el kardex: primero se
            // gastan los insumos y después aparece el producto. Al revés, el saldo del producible subiría antes de que
            // existiera con qué hacerlo.
            $output = $this->movements->record(
                warehouse: $warehouse,
                article: $article,
                kind: StockMovementKind::ProductionIn,
                quantity: $quantity,
                source: $locked,
                idempotencyKey: "production:{$locked->id}:output",
                notes: 'Producción',
            );

            $locked->update([
                'status' => ProductionOrderStatus::Completed,
                'produced_quantity' => $quantity,
                'unit_cost_at_production' => $output->unit_cost,
                'produced_by_membership_id' => $membershipId,
                'produced_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        });
    }

    /**
     * Cancela una orden planeada. No hay nada que deshacer: un borrador nunca movió inventario.
     *
     * @throws ProductionInvariantException
     */
    public function cancel(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order): ProductionOrder {
            $locked = ProductionOrder::query()->lockForUpdate()->whereKey($order->id)->sole();

            if (! $locked->isOpen()) {
                throw ProductionInvariantException::notOpen();
            }

            $locked->update(['status' => ProductionOrderStatus::Cancelled]);

            return $locked;
        });
    }

    /**
     * Consume un insumo y deja su renglón en la orden.
     *
     * Pasa por `IssueStock` y no por el registro directo, así que un insumo con lotes se surte por FEFO y **puede
     * partirse en varios movimientos**. Cada movimiento se convierte en un renglón: así el documento dice de qué
     * partida física salió cada gramo, que es lo que se necesita cuando un lote resulta defectuoso.
     */
    private function consume(
        ProductionOrder $order,
        ProductionConsumption $consumption,
        Warehouse $warehouse,
        int $index,
    ): void {
        $movements = $this->issues->issue(
            warehouse: $warehouse,
            article: $consumption->component,
            kind: StockMovementKind::ProductionOut,
            quantity: $consumption->quantityInBaseUnit,
            source: $order,

            // Por ÍNDICE de consumo: `IssueStock` le añade el suyo por cada lote, así que la llave final es única por
            // movimiento. Sin el índice del consumo, dos insumos de la misma orden compartirían llave y el segundo se
            // tomaría por un reintento del primero — la producción consumiría un solo insumo.
            idempotencyKey: "production:{$order->id}:consumption:{$index}",
            notes: 'Consumo por producción',
        );

        foreach ($movements as $movement) {
            ProductionOrderLine::create([
                'production_order_id' => $order->id,
                'component_article_id' => $consumption->component->id,
                'lot_id' => $movement->lot_id,
                'recipe_quantity' => $consumption->recipeQuantity,
                'recipe_unit_id' => $consumption->recipeUnitId,
                'yield_percent' => $consumption->yieldPercent,
                'consumed_quantity' => $movement->quantity,
                'unit_cost_at_production' => $movement->unit_cost,
                'movement_id' => $movement->id,
            ]);
        }
    }

    /**
     * Lo que una orden consumiría hoy, sin escribir nada.
     *
     * Es la previsualización del borrador, y existe para no tener que congelar las líneas al planear: la pantalla
     * pregunta «¿qué va a gastar esto?» y la respuesta se calcula de la receta vigente.
     *
     * @return list<ProductionConsumption>
     *
     * @throws ProductionInvariantException
     */
    public function preview(ProductionOrder $order): array
    {
        /** @var numeric-string $quantity */
        $quantity = $order->produced_quantity ?? $order->planned_quantity;

        return $this->consumption->forQuantity($order->article, $quantity);
    }

    /**
     * El costo unitario que tendría el producible hoy. Sólo para previsualizar.
     *
     * @return numeric-string|null
     */
    public function previewUnitCost(Article $article): ?string
    {
        return $this->costs->current($article);
    }

    private function requireMembership(): int
    {
        $membershipId = $this->context->getOrNull()?->membership?->id;

        if ($membershipId === null) {
            // Una producción la hace una persona. A diferencia de un movimiento que un job puede registrar sin actor,
            // el documento existe para decir quién produjo.
            throw new \LogicException('Una orden de producción exige una membresía en contexto.');
        }

        return $membershipId;
    }

    /**
     * Los movimientos que una orden generó, para poder mostrarlos juntos.
     *
     * @return Collection<int, StockMovement>
     */
    public function movementsOf(ProductionOrder $order): Collection
    {
        return StockMovement::query()
            ->where('source_type', ProductionOrder::class)
            ->where('source_id', $order->id)
            ->with(['article.baseUnit', 'lot'])
            ->get();
    }
}

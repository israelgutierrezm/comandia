<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\ProductionWorkflow;
use App\Modules\Inventory\Domain\ProductionConsumption;
use App\Modules\Inventory\Http\Concerns\AssertsWarehouseScope;
use App\Modules\Inventory\Http\Requests\CompleteProductionOrderRequest;
use App\Modules\Inventory\Http\Requests\StoreProductionOrderRequest;
use App\Modules\Inventory\Http\Resources\ProductionOrderResource;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Órdenes de producción (D17, P8).
 *
 * Un solo permiso, `inventory.production.create`, y no el `inventory.entries.create` que proponía el §7 del diseño:
 * producir **consume** inventario además de generarlo, así que reusar el permiso de entradas dejaría que quien sólo
 * puede meter mercancía pudiera sacarla — por un camino que ni pasa por el endpoint de salidas.
 */
final class ProductionOrderController
{
    use AssertsWarehouseScope;

    public function __construct(private readonly ProductionWorkflow $production) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, ProductionOrder>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['created_at', 'produced_at'],
            // Sin búsqueda de texto: una orden se busca por artículo o por estado, no por palabras. Declararlo vacío
            // hace que `?search=` se rechace en lugar de ignorarse (D182).
            searchable: [],
            defaultSort: '-created_at',
            dateRanges: ['created' => 'created_at', 'produced' => 'produced_at'],
            handledByCaller: ['warehouse', 'article', 'only_planned'],
        );

        $orders = $query->apply($this->baseQuery(), $request);

        // «Qué está planeado»: la consulta con la que se abre el día y la que alimenta la lista de compra.
        if ($request->boolean('only_planned')) {
            $orders->planned();
        }

        if ($request->filled('warehouse')) {
            $orders->whereHas('warehouse', fn ($q) => $q->where('ulid', $request->string('warehouse')));
        }

        if ($request->filled('article')) {
            $orders->whereHas('article', fn ($q) => $q->where('ulid', $request->string('article')));
        }

        return ProductionOrderResource::collection($orders->paginate($query->perPage($request)));
    }

    public function show(ProductionOrder $productionOrder): ProductionOrderResource
    {
        return $this->resource($productionOrder);
    }

    public function store(StoreProductionOrderRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();
        $article = Article::query()->where('ulid', $request->string('article_ulid'))->sole();

        $this->assertWarehouseInScope($warehouse);

        $order = $this->production->plan(
            warehouse: $warehouse,
            article: $article,
            plannedQuantity: $request->string('planned_quantity')->toString(),
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return $this->resource($order)->response()->setStatusCode(201);
    }

    public function complete(
        CompleteProductionOrderRequest $request,
        ProductionOrder $productionOrder,
    ): ProductionOrderResource {
        $this->assertWarehouseInScope($productionOrder->warehouse);

        $completed = $this->production->complete(
            order: $productionOrder,
            producedQuantity: $request->filled('produced_quantity')
                ? $request->string('produced_quantity')->toString()
                : null,
        );

        return $this->resource($completed);
    }

    public function cancel(ProductionOrder $productionOrder): ProductionOrderResource
    {
        $this->assertWarehouseInScope($productionOrder->warehouse);

        return $this->resource($this->production->cancel($productionOrder));
    }

    /**
     * El recurso con lo que corresponda a su estado: previsualización si es borrador, renglones si ya se produjo.
     *
     * Se decide aquí y no en el recurso porque calcular la previsualización toca la receta y el conversor de unidades,
     * y un `Resource` que dispara ese cálculo lo haría también en los listados — una consulta de recetas por fila.
     */
    private function resource(ProductionOrder $order): ProductionOrderResource
    {
        $order->refresh()->load([
            'article.baseUnit',
            'warehouse',
            'createdBy.user',
            'producedBy.user',
            'lines.component.baseUnit',
            'lines.lot',
            'lines.recipeUnit',
            'lines.movement',
        ]);

        $resource = new ProductionOrderResource($order);

        if ($order->isOpen()) {
            $resource->preview = array_map(
                fn (ProductionConsumption $consumption): array => [
                    'component' => [
                        'ulid' => $consumption->component->ulid,
                        'name' => $consumption->component->name,
                        'base_unit_code' => $consumption->component->baseUnit?->code,
                    ],
                    'quantity' => $consumption->quantityInBaseUnit,
                    'recipe' => [
                        'quantity' => $consumption->recipeQuantity,
                        'yield_percent' => $consumption->yieldPercent,
                    ],
                ],
                $this->production->preview($order),
            );
        }

        return $resource;
    }

    /**
     * @return Builder<ProductionOrder>
     */
    private function baseQuery(): Builder
    {
        // Sin las líneas ni la previsualización: un listado no las muestra, y cargarlas costaría una consulta de
        // recetas por fila.
        return ProductionOrder::query()->with([
            'article.baseUnit',
            'warehouse',
            'createdBy.user',
            'producedBy.user',
        ]);
    }
}

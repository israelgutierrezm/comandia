<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\CaptureCountLines;
use App\Modules\Inventory\Application\CloseStockCount;
use App\Modules\Inventory\Application\OpenStockCount;
use App\Modules\Inventory\Http\Concerns\AssertsWarehouseScope;
use App\Modules\Inventory\Http\Requests\CloseStockCountRequest;
use App\Modules\Inventory\Http\Requests\StoreStockCountRequest;
use App\Modules\Inventory\Http\Requests\UpdateStockCountLinesRequest;
use App\Modules\Inventory\Http\Resources\StockCountResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Conteos físicos (D24, §6.2).
 *
 * Cinco endpoints y dos permisos: `inventory.counts.create` para abrir y capturar, `inventory.counts.close` para
 * cerrar y cancelar. La frontera no es caprichosa — quien cuenta no decide que su conteo es la verdad — y es la
 * misma frontera que decide qué se ve del conteo ciego.
 */
final class StockCountController
{
    use AssertsWarehouseScope;

    public function __construct(
        private readonly OpenStockCount $opens,
        private readonly CaptureCountLines $captures,
        private readonly CloseStockCount $closes,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, StockCount>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            // `status` y nada más: los conteos se buscan por almacén y por estado, no por texto. `warehouse`
            // lo traduce el controlador de ULID a llave interna.
            filters: ['status' => 'status'],
            sortable: ['started_at', 'closed_at'],
            // Sin búsqueda de texto: lo único textual de un conteo son sus notas, y buscar notas no es una
            // pregunta que nadie haga. Declararlo vacío es lo que hace que `?search=` se rechace en lugar de
            // ignorarse.
            searchable: [],
            defaultSort: '-started_at',
            dateRanges: ['started' => 'started_at'],
            handledByCaller: ['warehouse'],
        );

        $counts = $query->apply(
            StockCount::query()->with([
                'warehouse',
                'startedBy.user',
                'closedBy.user',
                'authorizedBy.user',
            ]),
            $request,
        );

        if ($request->filled('warehouse')) {
            $counts->whereHas('warehouse', fn ($q) => $q->where('ulid', $request->string('warehouse')));
        }

        return StockCountResource::collection($counts->paginate($query->perPage($request)));
    }

    public function show(StockCount $stockCount): StockCountResource
    {
        // Las líneas con su artículo y su lote: es la hoja de conteo, y sin ellas la pantalla no existe. El
        // Resource decide qué columnas de cada línea viajan, según quien pregunte.
        $stockCount->load([
            'warehouse',
            'startedBy',
            'closedBy',
            'authorizedBy',
            'lines.article.baseUnit',
            'lines.lot',
            'lines.adjustmentMovement',
        ]);

        return new StockCountResource($stockCount);
    }

    public function store(StoreStockCountRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();

        $this->assertWarehouseInScope($warehouse);

        $articles = $request->has('article_ulids')
            ? Article::query()->whereIn('ulid', $request->input('article_ulids'))->get()->all()
            : [];

        $count = $this->opens->open(
            warehouse: $warehouse,
            articles: $articles,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return (new StockCountResource($count->load(['warehouse', 'startedBy', 'lines.article.baseUnit', 'lines.lot'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Captura masiva. `PUT` y no `PATCH` porque el efecto de mandar la misma hoja dos veces es el mismo que
     * mandarla una: los renglones que trae se escriben, los que no trae se quedan como estaban.
     */
    public function updateLines(UpdateStockCountLinesRequest $request, StockCount $stockCount): StockCountResource
    {
        $this->assertWarehouseInScope($stockCount->warehouse);

        /** @var array<int, array{article_ulid: string, lot_ulid?: string|null, counted_quantity: string|null}> $input */
        $input = $request->input('lines');

        $articles = Article::query()
            ->whereIn('ulid', array_column($input, 'article_ulid'))
            ->get()
            ->keyBy('ulid');

        $lotUlids = array_values(array_filter(array_column($input, 'lot_ulid')));

        $lots = $lotUlids === []
            ? collect()
            : ArticleLot::query()->whereIn('ulid', $lotUlids)->get()->keyBy('ulid');

        $entries = array_map(fn (array $line): array => [
            'article' => $articles[$line['article_ulid']],
            'lot' => isset($line['lot_ulid']) && $line['lot_ulid'] !== null ? $lots[$line['lot_ulid']] : null,

            // Se normaliza a cadena aquí y no en el servicio: lo que llega del JSON puede ser un número, y la
            // aritmética de bcmath sobre un float convertido es exactamente el error que §7 prohíbe.
            'counted_quantity' => $line['counted_quantity'] === null ? null : (string) $line['counted_quantity'],
        ], $input);

        $this->captures->capture($stockCount, $entries);

        return $this->show($stockCount->refresh());
    }

    public function close(CloseStockCountRequest $request, StockCount $stockCount): StockCountResource
    {
        $this->assertWarehouseInScope($stockCount->warehouse);

        $closed = $this->closes->close(
            count: $stockCount,
            authorizationToken: $request->filled('authorization_token')
                ? $request->string('authorization_token')->toString()
                : null,
        );

        return $this->show($closed);
    }

    public function cancel(StockCount $stockCount): StockCountResource
    {
        $this->assertWarehouseInScope($stockCount->warehouse);

        return $this->show($this->closes->cancel($stockCount));
    }
}

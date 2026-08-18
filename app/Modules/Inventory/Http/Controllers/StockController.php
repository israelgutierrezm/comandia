<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Http\Resources\ArticleStockResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Consulta de existencias.
 *
 * Tres lecturas, y las tres son la misma tabla vista desde ángulos distintos porque son tres preguntas
 * distintas del negocio:
 *
 *   - `GET /stocks` — «¿qué tengo?», con filtros. La vista del dueño.
 *   - `GET /articles/{ulid}/stock` — «¿dónde está mi queso?». Un artículo en todos sus almacenes.
 *   - `GET /warehouses/{ulid}/stocks` — «¿qué hay en este almacén?». La vista del almacenista.
 *
 * Cada una tiene su índice, y por eso están separadas en lugar de resolverse con filtros del primero: los tres
 * índices de `article_stocks` existen para estas tres consultas.
 */
final class StockController
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, ArticleStock>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [],
            // Por cantidad para ver primero lo que está en negativo o por agotarse, y por fecha para ver lo
            // que se movió al último. No por nombre de artículo: eso exigiría un `join` y un `filesort` sobre
            // una tabla que crece con el catálogo × los almacenes.
            sortable: ['quantity', 'updated_at'],
            searchable: [],
            defaultSort: 'quantity',
            // Los tres los traduce el controlador: `article` y `warehouse` de ULID a llave interna, y
            // `only_negative` a una condición. Siguen en la whitelist — sólo los aplica otro.
            handledByCaller: ['article', 'warehouse', 'only_negative'],
        );

        $stocks = $query->apply($this->baseQuery(), $request);

        $this->applyArticleFilter($stocks, $request);
        $this->applyWarehouseFilter($stocks, $request);

        // «Lo que está en negativo» no es una anomalía a corregir: §6.2 las permite porque el POS nunca se
        // bloquea. Es la lista de lo que el próximo conteo tiene que revisar, y por eso merece filtro propio.
        if ($request->boolean('only_negative')) {
            $stocks->negative();
        }

        return ArticleStockResource::collection($stocks->paginate($query->perPage($request)));
    }

    /**
     * Un artículo en todos sus almacenes.
     *
     * Sin paginar: un negocio tiene unos cuantos almacenes, y paginar tres filas obligaría al cliente a
     * recorrer páginas para sumar el total de un artículo — que es justo lo que esta vista responde.
     *
     * @return AnonymousResourceCollection<Collection<int, ArticleStock>>
     */
    public function forArticle(Article $article): AnonymousResourceCollection
    {
        $stocks = $this->baseQuery()
            ->where('article_id', $article->id)
            ->orderByDesc('quantity')
            ->get();

        return ArticleStockResource::collection($stocks);
    }

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, ArticleStock>>
     */
    public function forWarehouse(Request $request, Warehouse $warehouse): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [],
            sortable: ['quantity', 'updated_at'],
            searchable: [],
            defaultSort: 'quantity',
            handledByCaller: ['only_negative'],
        );

        $stocks = $query->apply(
            $this->baseQuery()->where('warehouse_id', $warehouse->id),
            $request,
        );

        if ($request->boolean('only_negative')) {
            $stocks->negative();
        }

        return ArticleStockResource::collection($stocks->paginate($query->perPage($request)));
    }

    /**
     * La consulta base con todo lo que el recurso pinta, cargado por adelantado.
     *
     * Sin esto sería un N+1 de cuatro consultas por fila, y `preventLazyLoading` lo haría reventar en pruebas —
     * que es exactamente para lo que está puesto.
     *
     * @return Builder<ArticleStock>
     */
    private function baseQuery(): Builder
    {
        return ArticleStock::query()->with([
            'article.baseUnit',
            'warehouse.branch',
            'lot',
        ]);
    }

    /**
     * @param  Builder<ArticleStock>  $query
     */
    private function applyArticleFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('article')) {
            return;
        }

        // Llega como ULID público y se traduce aquí: la API no acepta llaves internas (§7).
        $query->whereHas('article', fn ($q) => $q->where('ulid', $request->string('article')->toString()));
    }

    /**
     * @param  Builder<ArticleStock>  $query
     */
    private function applyWarehouseFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('warehouse')) {
            return;
        }

        $query->whereHas('warehouse', fn ($q) => $q->where('ulid', $request->string('warehouse')->toString()));
    }
}

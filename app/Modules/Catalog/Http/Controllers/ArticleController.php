<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Domain\Enums\ArticleStatus;
use App\Modules\Catalog\Http\Requests\StoreArticleRequest;
use App\Modules\Catalog\Http\Requests\UpdateArticleRequest;
use App\Modules\Catalog\Http\Resources\ArticleResource;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * El artículo unificado (D17).
 *
 * La autorización va en la ruta y evalúa el rol activo (D9). El global scope hace innecesario filtrar
 * por tenant, y el binding por ULID devuelve 404 —no 403— ante un identificador ajeno: no se confirma
 * la existencia de un recurso de otro negocio.
 */
final class ArticleController
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Article>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status', 'available_in_pos' => 'is_available_in_pos'],
            sortable: ['name', 'code', 'base_price', 'created_at'],
            searchable: ['name', 'short_name', 'code'],
            defaultSort: 'name',
            // `category`, `capability` y `branch` los traduce el controlador: el primero de ULID público a
            // llave interna, el segundo de nombre de capacidad a columna, el tercero determina qué
            // overrides precargar. Siguen en la whitelist — sólo los aplica otro.
            handledByCaller: ['category', 'capability', 'branch'],
        );

        $branch = $this->resolveBranch($request);

        $articles = $query
            ->apply(
                Article::query()->with(['baseUnit', 'category']),
                $request,
            );

        // Los overrides de ESA sucursal, precargados para todo el listado. Es la consulta del POS al pintar
        // su pantalla: sin la precarga, resolver el precio efectivo de 400 artículos serían 400 consultas.
        if ($branch !== null) {
            $articles->with([
                'branchOverrides' => fn ($q) => $q->where('branch_id', $branch->id),
            ]);
        }

        $this->applyCategoryFilter($articles, $request);
        $this->applyCapabilityFilter($articles, $request);

        return ArticleResource::collection($articles->paginate($query->perPage($request)));
    }

    public function store(StoreArticleRequest $request): JsonResponse
    {
        $unit = Unit::findByUlid($request->string('base_unit_ulid')->toString());
        $category = $request->filled('category_ulid')
            ? ArticleCategory::findByUlid($request->string('category_ulid')->toString())
            : null;

        // Transacción porque el artículo y sus etiquetas son una sola alta: un artículo creado sin
        // sus etiquetas parece completo y no lo está.
        $article = DB::transaction(function () use ($request, $unit, $category): Article {
            $article = Article::create([
                'code' => $request->filled('code') ? $request->string('code')->toString() : null,
                'name' => $request->string('name')->toString(),
                'short_name' => $request->filled('short_name')
                    ? $request->string('short_name')->toString()
                    : null,
                'category_id' => $category?->id,
                'base_unit_id' => $unit?->id,
                'is_sellable' => $request->boolean('is_sellable'),
                'is_inventoriable' => $request->boolean('is_inventoriable'),
                'is_supply' => $request->boolean('is_supply'),
                'is_producible' => $request->boolean('is_producible'),
                'base_price' => $request->filled('base_price') ? $request->string('base_price')->toString() : null,
                'markup_percent' => $request->filled('markup_percent')
                    ? $request->string('markup_percent')->toString()
                    : null,
                'is_available_in_pos' => $request->boolean('is_available_in_pos', true),
            ]);

            $this->syncTags($article, $request);

            return $article;
        });

        return (new ArticleResource($article->load(['baseUnit', 'category', 'tags'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Article $article): ArticleResource
    {
        $branch = $this->resolveBranch($request);

        $article->load(['baseUnit', 'category', 'tags', 'purchasePresentations']);

        if ($branch !== null) {
            $article->load([
                'branchOverrides' => fn ($q) => $q->where('branch_id', $branch->id),
            ]);
        }

        return new ArticleResource($article);
    }

    /**
     * La sucursal cuyos valores efectivos se piden, si se pidió alguna.
     *
     * Se resuelve **una vez** y su llave interna se deja en los atributos de la petición, de donde la lee el
     * Resource. La alternativa —que el Resource resolviera el ULID— costaría una consulta por fila del
     * listado, que es exactamente el problema que la precarga de overrides evita.
     *
     * Una sucursal inexistente o de otro negocio simplemente no se resuelve y el listado devuelve los datos
     * maestros: no se confirma la existencia de un recurso ajeno, y el cliente ve el catálogo del negocio en
     * lugar de un error sobre una sucursal que para él no existe.
     */
    private function resolveBranch(Request $request): ?Branch
    {
        if (! $request->filled('branch')) {
            return null;
        }

        $branch = Branch::findByUlid($request->string('branch')->toString());

        if ($branch !== null) {
            $request->attributes->set('effective_branch_id', $branch->id);
        }

        return $branch;
    }

    public function update(UpdateArticleRequest $request, Article $article): ArticleResource
    {
        $data = $request->safe()->except(['category_ulid', 'tag_ulids']);

        if ($request->has('category_ulid')) {
            $ulid = $request->string('category_ulid')->toString();

            $data['category_id'] = $ulid === ''
                ? null
                : ArticleCategory::findByUlid($ulid)?->id;
        }

        DB::transaction(function () use ($article, $data, $request): void {
            // El modelo impone los invariantes al guardar (vendible exige precio y categoría,
            // unidad base inmutable) y lanza excepción de dominio, que el proveedor del módulo
            // traduce a 422. Aquí no se repiten: dos verificaciones son dos sitios donde una puede
            // quedarse desactualizada.
            $article->update($data);

            if ($request->has('tag_ulids')) {
                $this->syncTags($article, $request);
            }
        });

        return new ArticleResource($article->refresh()->load(['baseUnit', 'category', 'tags']));
    }

    /**
     * Archivado, no borrado (D80).
     *
     * Hay historial de precios y de costos apuntando aquí, y desde la Iteración 4 habrá líneas de
     * venta. `archived` significa "no se puede usar desde hoy", nunca "no existió".
     */
    public function archive(Article $article): ArticleResource
    {
        $article->update([
            'status' => ArticleStatus::Archived,
            // Un artículo archivado que siguiera disponible en el POS sería una contradicción que
            // alguien descubriría al intentar venderlo.
            'is_available_in_pos' => false,
        ]);

        return new ArticleResource($article->refresh()->load(['baseUnit', 'category']));
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function applyCategoryFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('category')) {
            return;
        }

        $category = ArticleCategory::findByUlid($request->string('category')->toString());

        // Una categoría inexistente filtra por una llave imposible en lugar de devolver el catálogo
        // completo: devolverlo entero daría una lista que parece correcta a quien cree estar
        // filtrando.
        $ids = $category === null
            ? [0]
            : [$category->id, ...$category->children()->pluck('id')->all()];

        // Incluye las subcategorías: quien filtra por "Bebidas" espera ver también las de
        // "Bebidas > Cervezas". Lo contrario obligaría al cliente a conocer el árbol y a mandar N
        // identificadores.
        $query->whereIn('category_id', $ids);
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function applyCapabilityFilter(Builder $query, Request $request): void
    {
        if (! $request->filled('capability')) {
            return;
        }

        // Whitelist explícita: el nombre público no es el nombre de la columna, así que un valor
        // desconocido no puede convertirse en un `where` sobre una columna arbitraria.
        $column = match ($request->string('capability')->toString()) {
            'sellable' => 'is_sellable',
            'inventoriable' => 'is_inventoriable',
            'supply' => 'is_supply',
            'producible' => 'is_producible',
            default => null,
        };

        if ($column === null) {
            abort(422, 'Capacidad no válida. Usa sellable, inventoriable, supply o producible.');
        }

        $query->where($column, true);
    }

    private function syncTags(Article $article, Request $request): void
    {
        if (! $request->has('tag_ulids')) {
            return;
        }

        /** @var list<string> $ulids */
        $ulids = (array) $request->input('tag_ulids', []);

        // Se resuelven con el scope de tenant aplicado, así que un ULID de otro negocio simplemente
        // no aparece: el aislamiento no depende de que el cliente mande identificadores válidos.
        $ids = Tag::query()->whereIn('ulid', $ulids)->pluck('id')->all();

        $article->tags()->sync($ids);
    }
}

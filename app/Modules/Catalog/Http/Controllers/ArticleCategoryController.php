<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreArticleCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateArticleCategoryRequest;
use App\Modules\Catalog\Http\Resources\ArticleCategoryResource;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Categorías de artículos, dos niveles exactos (D18).
 *
 * El listado devuelve el **árbol completo** y no una página: son decenas de filas, el cliente las
 * necesita todas para pintar un selector jerárquico, y paginar un árbol obligaría al cliente a
 * reconstruirlo entre páginas.
 */
final class ArticleCategoryController
{
    /**
     * @return AnonymousResourceCollection<Collection<int, ArticleCategory>>
     */
    public function index(): AnonymousResourceCollection
    {
        // Una sola consulta con `with`: el índice (tenant_id, parent_id, sort_order) resuelve las dos
        // en un recorrido, y sin `with` pintar el árbol sería una consulta por categoría raíz.
        $roots = ArticleCategory::query()
            ->roots()
            ->with(['children' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ArticleCategoryResource::collection($roots);
    }

    public function store(StoreArticleCategoryRequest $request): JsonResponse
    {
        $parent = $request->filled('parent_ulid')
            ? ArticleCategory::findByUlid($request->string('parent_ulid')->toString())
            : null;

        // `level` se calcula, nunca llega del cliente: es redundante con `parent_id` y el CHECK de la
        // tabla rechaza la fila si se contradicen. Dejar que el cliente lo mandara sería dejarle
        // producir un 500 con datos perfectamente bien intencionados.
        $category = ArticleCategory::create([
            'name' => $request->string('name')->toString(),
            'parent_id' => $parent?->id,
            'level' => ArticleCategory::levelFor($parent),
            'sort_order' => $request->integer('sort_order'),
        ]);

        return (new ArticleCategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ArticleCategory $articleCategory): ArticleCategoryResource
    {
        return new ArticleCategoryResource($articleCategory->load(['parent', 'children']));
    }

    /**
     * Nombre, orden y estado. **El padre no se cambia**: mover una subcategoría de padre cambiaría la
     * clasificación de todos sus artículos de golpe, y en el POS eso significa que los platillos
     * aparecen en otra pestaña sin que nadie lo haya pedido. Se crea la categoría nueva y se
     * reasignan los artículos, que es explícito y reversible.
     */
    public function update(
        UpdateArticleCategoryRequest $request,
        ArticleCategory $articleCategory,
    ): ArticleCategoryResource {
        $articleCategory->update($request->validated());

        return new ArticleCategoryResource($articleCategory->refresh());
    }

    /**
     * Baja de categoría: cambio de estado, no borrado (D80).
     *
     * La FK de `articles.category_id` es RESTRICT, así que borrar una categoría con artículos daría
     * un error de base de datos con un mensaje que nadie entiende. Y aunque estuviera vacía hoy,
     * puede aparecer en el historial de precios de un artículo reasignado.
     */
    public function archive(ArticleCategory $articleCategory): ArticleCategoryResource
    {
        // Una categoría con subcategorías activas se desactivaría dejándolas huérfanas de un padre
        // visible: el árbol quedaría mostrando hijos de una rama inactiva. Se pide desactivar las
        // hojas primero, que es una decisión que el usuario tiene que tomar a la vista.
        $activeChildren = $articleCategory->children()
            ->where('status', 'active')
            ->count();

        if ($activeChildren > 0) {
            throw new HttpException(
                409,
                'Esta categoría tiene subcategorías activas. Desactívalas primero.'
            );
        }

        $articleCategory->update(['status' => 'inactive']);

        return new ArticleCategoryResource($articleCategory->refresh());
    }
}

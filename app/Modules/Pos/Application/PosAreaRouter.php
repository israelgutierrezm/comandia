<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Domain\Contracts\AreaRouter;

/**
 * Implementación de {@see AreaRouter} sobre el ruteo del POS (D240).
 *
 * `Pos` es el dueño de `pos_area_routes` y del algoritmo de precedencia, así que aquí es donde se resuelve la sonda del
 * kernel. Envuelve a {@see ResolveAreaRoute} traduciendo el id de artículo a su modelo —el resolutor necesita la categoría
 * y su padre—. Quien la consume (la tienda) sólo pasa primitivos y nunca toca `pos_area_routes`.
 */
final class PosAreaRouter implements AreaRouter
{
    public function __construct(private readonly ResolveAreaRoute $routes) {}

    public function routeForArticle(int $articleId, int $branchId): ?int
    {
        $article = Article::query()->whereKey($articleId)->first();

        if ($article === null) {
            return null;
        }

        return $this->routes->forArticle($article, $branchId);
    }

    public function rulesRoutingTo(int $preparationAreaId): array
    {
        return PosAreaRoute::query()
            ->where('preparation_area_id', $preparationAreaId)
            ->with(['article:id,name', 'category:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (PosAreaRoute $regla): string => $regla->article_id !== null
                ? sprintf('Artículo «%s»', $regla->article?->name ?? '—')
                : sprintf('Categoría «%s»', $regla->category?->name ?? '—'))
            ->values()
            ->all();
    }
}

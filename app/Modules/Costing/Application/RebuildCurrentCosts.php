<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye la proyección del costo vigente desde el historial.
 *
 * Es la tercera condición de P4, y no es un adorno: una proyección sin forma de reconstruirse es una
 * verdad paralela. Con este comando, la proyección es siempre desechable — si divergiera por un
 * camino de escritura que nadie previó, se tira y se vuelve a calcular.
 *
 * Corre dentro del contexto de tenant que tenga el llamador: el scope global se encarga de que sólo
 * toque un negocio. El comando de consola lo invoca tenant por tenant.
 */
final readonly class RebuildCurrentCosts
{
    /**
     * @return array{articles: int, projected: int, cleared: int}
     */
    public function forCurrentTenant(): array
    {
        return DB::transaction(function (): array {
            $articles = 0;
            $projected = 0;
            $cleared = 0;

            // `each` y no `get`: un catálogo grande no cabe cómodamente en memoria, y esto es una
            // operación de mantenimiento que puede correr sobre miles de artículos.
            Article::query()->select(['id'])->each(
                function (Article $article) use (&$articles, &$projected, &$cleared): void {
                    $articles++;

                    $current = ArticleCost::currentFor($article->id);

                    if ($current === null) {
                        // Un artículo sin historial no debe tener proyección. Si la tiene, es basura
                        // de un borrado incompleto y se limpia.
                        $cleared += ArticleCurrentCost::query()
                            ->where('article_id', $article->id)
                            ->delete();

                        return;
                    }

                    ArticleCurrentCost::query()->updateOrCreate(
                        ['article_id' => $article->id],
                        [
                            'unit_cost' => $current->unit_cost,
                            'effective_at' => $current->effective_at,
                            'source_cost_id' => $current->id,
                        ],
                    );

                    $projected++;
                }
            );

            return ['articles' => $articles, 'projected' => $projected, 'cleared' => $cleared];
        });
    }

    /**
     * Artículos cuya proyección NO coincide con su historial.
     *
     * Es lo que consulta la prueba de P4 y lo que reporta el comando en modo verificación. Devuelve
     * la discrepancia con los dos valores porque "divergen" sin decir en qué no sirve para
     * diagnosticar.
     *
     * @return list<array{article_id: int, projected: string|null, expected: string|null}>
     */
    public function divergences(): array
    {
        $found = [];

        Article::query()->select(['id'])->each(function (Article $article) use (&$found): void {
            $expected = ArticleCost::currentFor($article->id);

            $projection = ArticleCurrentCost::query()
                ->where('article_id', $article->id)
                ->first();

            $expectedCost = $expected?->unit_cost;
            $projectedCost = $projection?->unit_cost;

            // Comparación numérica y no de cadenas: '24.0000' y '24.00' son el mismo costo, y
            // compararlas como texto reportaría divergencias falsas todo el tiempo.
            $equal = $expectedCost === null && $projectedCost === null
                || ($expectedCost !== null && $projectedCost !== null
                    && bccomp($expectedCost, $projectedCost, 4) === 0);

            if (! $equal) {
                $found[] = [
                    'article_id' => $article->id,
                    'projected' => $projectedCost,
                    'expected' => $expectedCost,
                ];
            }
        });

        return $found;
    }
}

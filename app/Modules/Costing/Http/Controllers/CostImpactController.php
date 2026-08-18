<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Application\RecipeCycleDetector;
use App\Modules\Costing\Domain\RecipeGraph;
use Illuminate\Http\JsonResponse;

/**
 * Qué se ve afectado si cambia el costo de este artículo.
 *
 * Existe para mostrarse **antes** de capturar un costo. Subir el precio del jitomate de $20 a $60 el kilo
 * cambia el costo de catorce platillos, y quien lo captura tiene derecho a saberlo antes de guardar en lugar
 * de descubrirlo al día siguiente en un reporte de márgenes.
 *
 * Distingue **directo** de **indirecto**: el pan usa la masa directamente; la torta la usa a través del pan.
 * La diferencia importa porque el directo es donde el usuario puede intervenir —cambiar la cantidad de la
 * receta— y el indirecto no.
 */
final class CostImpactController
{
    public function __invoke(Article $article, RecipeCycleDetector $detector): JsonResponse
    {
        $graph = $detector->loadGraph();

        $dependentIds = $graph->dependentsOf($article->id);

        if ($dependentIds === []) {
            return new JsonResponse([
                'data' => [
                    'article_ulid' => $article->ulid,
                    'total' => 0,
                    'dependents' => [],
                ],
            ]);
        }

        $directIds = $this->directDependents($graph, $article->id, $dependentIds);

        // Una consulta para todos, y ordenada por nombre: es una lista que un humano va a leer.
        $dependents = Article::query()
            ->whereIn('id', $dependentIds)
            ->orderBy('name')
            ->get();

        return new JsonResponse([
            'data' => [
                'article_ulid' => $article->ulid,
                'total' => $dependents->count(),
                'dependents' => $dependents->map(fn (Article $dependent): array => [
                    'ulid' => $dependent->ulid,
                    'name' => $dependent->name,
                    'is_sellable' => $dependent->is_sellable,

                    // Directo = lo tiene como ingrediente en su propia receta. Indirecto = lo alcanza a
                    // través de una sub-receta.
                    'is_direct' => in_array($dependent->id, $directIds, strict: true),
                ])->values(),
            ],
        ]);
    }

    /**
     * De todos los dependientes, cuáles lo usan **directamente**.
     *
     * Se resuelve con el grafo ya cargado en lugar de una segunda consulta: un dependiente es directo si el
     * camino desde él hasta el artículo es de un solo salto.
     *
     * @param  list<int>  $dependentIds
     * @return list<int>
     */
    private function directDependents(RecipeGraph $graph, int $articleId, array $dependentIds): array
    {
        $direct = [];

        foreach ($dependentIds as $dependentId) {
            $path = $graph->findPath($dependentId, $articleId);

            if ($path !== null && count($path) === 2) {
                $direct[] = $dependentId;
            }
        }

        return $direct;
    }
}

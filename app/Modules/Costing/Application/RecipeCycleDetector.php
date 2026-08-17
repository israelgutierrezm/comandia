<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Domain\Exceptions\RecipeCycleException;
use App\Modules\Costing\Domain\RecipeGraph;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Detección obligatoria de ciclos en el grafo de recetas (D16).
 *
 * ## Por qué se valida ANTES de escribir y no con un job posterior
 *
 * Un ciclo guardado hace que el recálculo de costos **no termine nunca**. Descubrirlo en producción
 * significa una cola atascada, y arreglarlo exige borrar datos que el usuario cree correctos. Validar
 * después sería cambiar un error que el usuario puede corregir en el momento por una avería que sólo
 * un desarrollador puede diagnosticar.
 *
 * ## Se valida el estado POSTERIOR a la escritura
 *
 * Guardar una receta **reemplaza** sus líneas, así que validar línea por línea contra el grafo actual
 * respondería sobre un grafo que ya no va a existir: rechazaría combinaciones legítimas y aceptaría
 * ciclos que sólo aparecen con el conjunto completo.
 *
 * El razonamiento que hace esto suficiente: guardar una receta sólo cambia las aristas **salientes del
 * artículo dueño**, así que cualquier ciclo nuevo tiene que pasar por él. Basta preguntar si el dueño se
 * alcanza a sí mismo.
 */
final readonly class RecipeCycleDetector
{
    /**
     * @param  list<int>  $componentIds  los componentes que tendría la receta después de guardar
     *
     * @throws RecipeCycleException
     */
    public function assertNoCycle(Article $owner, array $componentIds): void
    {
        // El ciclo trivial, y el más probable por error de dedo. Se comprueba aparte para poder dar un
        // mensaje directo en lugar de un camino de un solo salto.
        if (in_array($owner->id, $componentIds, strict: true)) {
            throw RecipeCycleException::selfReference($owner->name);
        }

        $graph = $this->loadGraph()->replaceEdgesOf($owner->id, $componentIds);

        foreach ($componentIds as $componentId) {
            $path = $graph->findPath($componentId, $owner->id);

            if ($path === null) {
                continue;
            }

            // El camino empieza en el componente; se le antepone el dueño para que se lea como el ciclo
            // completo: «Torta → Pan → Masa → Torta».
            throw RecipeCycleException::withPath(
                $this->nameThem([$owner->id, ...$path])
            );
        }
    }

    /**
     * El grafo completo de recetas del tenant.
     *
     * Una sola consulta y recorrido en memoria. Es aceptable porque un catálogo de recetas son miles de
     * líneas, no millones: son artículos producibles del negocio, no transacciones. Si algún día no lo
     * fuera, la salida es una tabla de cierre transitivo y sería una decisión con ADR — queda dicho para
     * que nadie suponga que esto escala sin límite.
     *
     * Sólo recetas de artículos (`article_id` no nulo): la receta de un modificador consume insumos pero
     * nada la consume como ingrediente, así que no puede formar parte de un ciclo.
     */
    public function loadGraph(): RecipeGraph
    {
        // Consulta directa y no Eloquent: se necesitan dos columnas de una unión, no modelos hidratados.
        // El global scope no aplica a `DB::table`, así que el `tenant_id` va explícito — y va sobre
        // `recipes`, que es donde el índice lo tiene.
        $rows = DB::table('recipe_lines')
            ->join('recipes', 'recipes.id', '=', 'recipe_lines.recipe_id')
            ->where('recipes.tenant_id', app(TenantContext::class)->id())
            ->whereNotNull('recipes.article_id')
            ->select('recipes.article_id', 'recipe_lines.component_article_id')
            ->get();

        $edges = [];

        foreach ($rows as $row) {
            $edges[(int) $row->article_id][] = (int) $row->component_article_id;
        }

        return new RecipeGraph($edges);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function nameThem(array $ids): array
    {
        $names = Article::query()
            ->whereIn('id', array_unique($ids))
            ->pluck('name', 'id');

        return array_map(
            fn (int $id): string => (string) ($names[$id] ?? "artículo #{$id}"),
            $ids,
        );
    }
}

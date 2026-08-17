<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Http\Requests\SaveRecipeRequest;
use App\Modules\Costing\Http\Resources\RecipeResource;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La receta de un artículo (D16, D21).
 *
 * Un solo recurso por artículo —invariante I1— así que no hay listado ni identificador propio en la URL:
 * `/articles/{article}/recipe`. Un `GET` de un artículo sin receta devuelve 404 y no una receta vacía:
 * "no tiene receta" y "tiene una receta sin ingredientes" son estados distintos, y el segundo no existe.
 */
final class RecipeController
{
    public function __construct(private readonly SaveRecipe $recipes) {}

    public function show(Article $article): RecipeResource
    {
        return new RecipeResource($this->loadOrFail($article));
    }

    /**
     * Guarda la receta completa, reemplazando la anterior.
     *
     * Devuelve **200 y no 201** incluso cuando la crea: el recurso es único por artículo, su URL existe
     * siempre y `PUT` es un reemplazo idempotente, así que no hay recurso nuevo que anunciar.
     *
     * El código se fija explícitamente porque Laravel devuelve 201 por su cuenta cuando el modelo que
     * envuelve el Resource acaba de crearse (`wasRecentlyCreated`). Es una comodidad razonable para un
     * `POST` de colección y aquí sería una incoherencia: el mismo `PUT` respondería 201 la primera vez y
     * 200 las siguientes, con lo que el cliente tendría que tratar dos códigos para una sola operación.
     */
    public function update(SaveRecipeRequest $request, Article $article): JsonResponse
    {
        $outputUnit = $request->filled('output_unit_ulid')
            ? Unit::findByUlid($request->string('output_unit_ulid')->toString())
            : null;

        // Los ULID se traducen a llaves internas aquí. El servicio recibe llaves porque es dominio y no
        // tiene por qué conocer la representación pública de los identificadores.
        $lines = [];

        foreach ((array) $request->input('lines', []) as $index => $line) {
            $component = Article::findByUlid((string) $line['component_ulid']);
            $unit = Unit::findByUlid((string) $line['unit_ulid']);

            // El Form Request ya verificó que los dos existen dentro del tenant.
            assert($component !== null && $unit !== null);

            $lines[] = [
                'component_article_id' => $component->id,
                'quantity' => (string) $line['quantity'],
                'unit_id' => $unit->id,
                'yield_percent' => isset($line['yield_percent'])
                    ? (string) $line['yield_percent']
                    : '100.00',
                'sort_order' => isset($line['sort_order']) ? (int) $line['sort_order'] : $index,
            ];
        }

        // Las excepciones de dominio —ciclo, invariantes— las traduce a 422 el proveedor del módulo. No
        // se capturan aquí: el controlador no tiene nada mejor que hacer con ellas que el manejador.
        $recipe = $this->recipes->save(
            article: $article,
            lines: $lines,
            outputQuantity: $request->string('output_quantity')->toString(),
            outputUnitId: $outputUnit?->id,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return (new RecipeResource($this->load($recipe)))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Elimina la receta.
     *
     * El artículo **no** deja de ser producible: eso es una decisión de catálogo con su propio permiso.
     * Lo que desaparece es su composición, y con ella su costo calculable.
     */
    public function destroy(Article $article): JsonResponse
    {
        $this->recipes->delete($this->loadOrFail($article));

        return new JsonResponse(status: 204);
    }

    private function loadOrFail(Article $article): Recipe
    {
        $recipe = Recipe::query()->where('article_id', $article->id)->first();

        if ($recipe === null) {
            // 404 y no una receta vacía: "no tiene receta" es un estado real y distinto.
            throw new NotFoundHttpException('Este artículo no tiene receta.');
        }

        return $this->load($recipe);
    }

    private function load(Recipe $recipe): Recipe
    {
        return $recipe->load([
            'article',
            'outputUnit',
            // `component.baseUnit` en el eager load: sin él, pintar la receta haría una consulta por
            // línea para resolver la unidad base de cada ingrediente.
            'lines' => fn ($query) => $query->orderBy('sort_order'),
            'lines.component.baseUnit',
            'lines.unit',
        ]);
    }
}

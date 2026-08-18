<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CalculateArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Http\Requests\SaveRecipeRequest;
use App\Modules\Costing\Http\Resources\RecipeResource;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Receta y costo de un MODIFICADOR (§6.1: "impacto en receta por unidad").
 *
 * «Extra queso» consume 30 g de queso. Sin esto, el platillo con extras costaría lo mismo que sin ellos y el
 * margen del extra saldría del 100 % — el error apunta siempre en la dirección optimista.
 *
 * La receta se guarda **completa**, igual que la de un artículo: es una unidad de sentido. Y rinde siempre una
 * aplicación, así que el endpoint no acepta rendimiento — si el grupo admite cantidad (los "3 shots" de D7), es
 * el POS quien multiplica.
 */
final class ModifierRecipeController
{
    public function __construct(private readonly SaveRecipe $recipes) {}

    public function show(Modifier $modifier): RecipeResource
    {
        return new RecipeResource($this->loadOrFail($modifier));
    }

    /**
     * Guarda la receta del modificador, reemplazando la anterior.
     *
     * Reutiliza el Form Request de la receta de artículo: las reglas de las líneas son las mismas —cantidad,
     * unidad, rendimiento, sin ingredientes repetidos— y duplicarlas sería duplicar el sitio donde se corrigen.
     * `output_quantity` llega validado y se **ignora**: lo fija el servicio en 1.
     */
    public function update(SaveRecipeRequest $request, Modifier $modifier): JsonResponse
    {
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
                'yield_percent' => isset($line['yield_percent']) ? (string) $line['yield_percent'] : '100.00',
                'sort_order' => isset($line['sort_order']) ? (int) $line['sort_order'] : $index,
            ];
        }

        $recipe = $this->recipes->saveForModifier(
            modifier: $modifier,
            lines: $lines,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        // 200 y no 201 por lo mismo que la receta de artículo (D103): el recurso es único por modificador y su
        // URL existe siempre, así que no hay recurso nuevo que anunciar.
        return (new RecipeResource($this->load($recipe)))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Modifier $modifier): JsonResponse
    {
        $this->recipes->delete($this->loadOrFail($modifier));

        return new JsonResponse(status: 204);
    }

    /**
     * El costo de aplicar este modificador una vez.
     *
     * Un modificador **sin receta cuesta cero**, no "desconocido": «término medio» no gasta insumos. Es la
     * diferencia con un artículo sin costo capturado, y confundirlas haría incalculable el platillo entero por
     * llevar un modificador que no consume nada.
     */
    public function cost(Modifier $modifier, CalculateArticleCost $calculator): JsonResponse
    {
        $breakdown = $calculator->modifierBreakdown($modifier);

        return new JsonResponse([
            'data' => [
                'modifier_ulid' => $modifier->ulid,
                'modifier_name' => $modifier->name,

                'extra_price' => $modifier->extra_price,

                'unit_cost' => $breakdown->unitCost,
                'is_computable' => $breakdown->isComputable(),
                'missing_costs' => $breakdown->missingCosts,

                'lines' => array_map(fn ($line): array => [
                    'component_ulid' => $line->componentUlid,
                    'component_name' => $line->componentName,
                    'quantity' => $line->quantity,
                    'unit_code' => $line->unitCode,
                    'quantity_in_base_unit' => $line->quantityInComponentBaseUnit,
                    'base_unit_code' => $line->componentBaseUnitCode,
                    'component_unit_cost' => $line->componentUnitCost,
                    'yield_percent' => $line->yieldPercent,
                    'line_cost' => $line->lineCost,
                ], $breakdown->lines),
            ],
        ]);
    }

    private function loadOrFail(Modifier $modifier): Recipe
    {
        $recipe = Recipe::query()->where('modifier_id', $modifier->id)->first();

        if ($recipe === null) {
            // 404 y no una receta vacía: "no consume insumos" es un estado real y distinto de "tiene una receta
            // sin ingredientes", que no existe porque se rechaza.
            throw new NotFoundHttpException('Este modificador no tiene receta.');
        }

        return $this->load($recipe);
    }

    private function load(Recipe $recipe): Recipe
    {
        return $recipe->load([
            'modifier',
            'outputUnit',
            'lines' => fn ($query) => $query->orderBy('sort_order'),
            'lines.component.baseUnit',
            'lines.unit',
        ]);
    }
}

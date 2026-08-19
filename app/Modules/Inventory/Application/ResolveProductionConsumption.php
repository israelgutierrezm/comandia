<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Domain\UnitConverter;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Inventory\Domain\Exceptions\ProductionInvariantException;
use App\Modules\Inventory\Domain\ProductionConsumption;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * Qué se consume al producir una cantidad de un artículo producible.
 *
 * ## La fórmula
 *
 *     por cada línea L de la receta activa de A:
 *         cantidad_base = L.quantity convertida a la unidad base del componente
 *         necesario     = cantidad_base ÷ (L.yield_percent / 100)
 *         consumo       = necesario × (producido ÷ rinde_en_base)
 *
 * El **rendimiento divide** (D21), igual que en el costeo, y aquí se ve por qué: 200 g utilizables al 80 % exigen
 * sacar 250 g del almacén. En el costeo eso encarece la línea; aquí saca más mercancía del estante. Es el mismo
 * divisor aplicado a dos cosas distintas, y las dos son ciertas.
 *
 * ## Por qué NO se reutiliza el desglose de costeo para las cantidades
 *
 * Sería lo natural —`CalculateArticleCost` ya convierte unidades y aplica rendimiento— y no sirve, porque **la
 * travesía es distinta**: el costeo *siempre* recursa hacia abajo, porque para valuar una salsa necesita el costo de
 * su masa. La producción no debe recursar: si la masa es un artículo inventariable, produciendo salsa se consume
 * **masa**, no la harina y la levadura con las que se hizo la masa — ésas ya se consumieron cuando alguien produjo
 * la masa. Explotar la receta hacia abajo sería consumir dos veces los mismos insumos.
 *
 * Lo que sí se comparte son las piezas donde la aritmética podría divergir: el `UnitConverter` y el
 * `yieldDivisor()` de la línea de receta son los mismos objetos que usa el costeo.
 *
 * ## Un componente que no se inventaría se RECHAZA
 *
 * Un producible sin capacidad de inventario es una **sub-receta de cálculo**: existe para costear, no tiene
 * existencias, y consumirlo dejaría un saldo negativo creciendo para siempre en un artículo que nadie mira.
 *
 * En v1 se rechaza con un mensaje que dice cómo arreglarlo —marcarlo inventariable, o sustituirlo por sus insumos—
 * en lugar de explotar la receta hacia abajo sólo para ese caso. **Deuda declarada:** la explosión selectiva es la
 * evolución natural, y se dejó fuera porque obligaría a que una misma orden tuviera consumos de dos travesías
 * distintas, con el mismo componente llegando por dos caminos y renglones cuyo origen ya no se podría explicar.
 */
final class ResolveProductionConsumption
{
    public function __construct(private readonly UnitConverter $converter) {}

    /**
     * @param  numeric-string  $producedQuantity  en la unidad base del producible
     * @return list<ProductionConsumption>
     *
     * @throws ProductionInvariantException
     */
    public function forQuantity(Article $article, string $producedQuantity): array
    {
        $recipe = $this->activeRecipeOf($article);

        $scale = $this->scaleFactor($article, $recipe, $producedQuantity);

        $consumptions = [];

        foreach ($recipe->lines as $line) {
            $component = $line->component;

            if ($component === null || $component->baseUnit === null || $line->unit === null) {
                // No debería ocurrir: las FK son RESTRICT y `SaveRecipe` valida al guardar. Si ocurre, saltarlo
                // silenciosamente produciría una producción que consume de menos, así que se dice.
                throw ProductionInvariantException::emptyRecipe($article->name);
            }

            // La capacidad de inventario decide si el componente se puede consumir. No es una bandera nueva: es la
            // misma que usa todo el módulo para saber si un artículo tiene existencias (D17).
            if (! $component->is_inventoriable) {
                throw ProductionInvariantException::componentIsNotInventoriable($component->name);
            }

            $quantityInBase = $this->converter->convert($line->quantity, $line->unit, $component->baseUnit);

            $required = Decimal::divide($quantityInBase, $line->yieldDivisor(), UnitConverter::SCALE);

            $consumptions[] = new ProductionConsumption(
                component: $component,
                quantityInBaseUnit: Decimal::round(bcmul($required, $scale, UnitConverter::SCALE), 4),
                recipeQuantity: $line->quantity,
                recipeUnitId: $line->unit->id,
                yieldPercent: $line->yield_percent,
            );
        }

        if ($consumptions === []) {
            throw ProductionInvariantException::emptyRecipe($article->name);
        }

        return $consumptions;
    }

    /**
     * La receta activa del producible, con lo que hace falta para escalarla.
     *
     * @throws ProductionInvariantException
     */
    public function activeRecipeOf(Article $article): Recipe
    {
        if (! $article->is_producible) {
            throw ProductionInvariantException::notProducible($article->name);
        }

        $recipe = Recipe::query()
            ->where('article_id', $article->id)
            ->where('status', 'active')
            ->with(['lines.component.baseUnit', 'lines.unit', 'outputUnit'])
            ->first();

        if ($recipe === null) {
            throw ProductionInvariantException::withoutRecipe($article->name);
        }

        return $recipe;
    }

    /**
     * Cuántas veces la receta cabe en lo que se quiere producir.
     *
     * La receta rinde `output_quantity` en su unidad de salida; lo producido llega en la unidad base del artículo, que
     * es la del kardex. Convertir es obligatorio: una receta que rinde «1 L» y una producción de «500 ml» son la mitad,
     * no quinientas veces.
     *
     * @param  numeric-string  $producedQuantity
     * @return numeric-string
     *
     * @throws ProductionInvariantException
     */
    private function scaleFactor(Article $article, Recipe $recipe, string $producedQuantity): string
    {
        if (bccomp($producedQuantity, '0', 4) !== 1) {
            throw ProductionInvariantException::nonPositiveQuantity();
        }

        $outputInBase = $recipe->outputUnit !== null && $article->baseUnit !== null
            ? $this->converter->convert($recipe->output_quantity, $recipe->outputUnit, $article->baseUnit)
            : $recipe->output_quantity;

        if (bccomp($outputInBase, '0', UnitConverter::SCALE) !== 1) {
            throw ProductionInvariantException::recipeYieldsNothing($article->name);
        }

        return Decimal::divide($producedQuantity, $outputInBase, UnitConverter::SCALE);
    }
}

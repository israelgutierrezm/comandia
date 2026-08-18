<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Domain\UnitConverter;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Costing\Domain\CostBreakdown;
use App\Modules\Costing\Domain\CostBreakdownLine;
use App\Modules\Costing\Domain\Exceptions\CostCycleDetectedException;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * El motor de costeo en cascada (D16, D21).
 *
 * ## La fórmula, escrita una sola vez
 *
 *     por cada línea L de la receta R:
 *         cantidad_base = L.quantity convertida a la unidad base del componente
 *         costo_línea   = costo(componente) × cantidad_base ÷ (L.yield_percent / 100)
 *
 *     total    = Σ costo_línea
 *     rinde    = R.output_quantity convertido a la unidad base del artículo
 *     costo(A) = total ÷ rinde
 *
 * `costo(componente)` es **recursivo**: si el componente tiene receta activa se calcula desde ella; si no,
 * se toma su costo capturado. Es exactamente la doble modalidad de D16 —producible con receta, o insumo
 * con costo capturado— y la que decide es la existencia de la receta, no una bandera aparte.
 *
 * ## Por qué recalcula en lugar de leer la proyección del componente
 *
 * Porque la proyección de una sub-receta puede estar desactualizada, y heredar ese valor propagaría el
 * desfase hacia arriba sin dejar rastro. Recalcular es determinista: el mismo catálogo da el mismo número
 * siempre. La proyección existe para quien sólo necesita "el costo" —inventarios al valuar, el POS—, no
 * para alimentar este cálculo.
 *
 * El costo se paga con memoización: en un grafo en diamante —el pan y la salsa usan los dos la misma
 * masa— la masa se costea **una vez** por cálculo.
 *
 * ## Un componente sin costo NO vale cero
 *
 * Si a cualquier profundidad falta un costo, el resultado **no es calculable** y se reportan los
 * componentes que faltan. La alternativa —sumar los que sí se conocen— produciría un costo más bajo que el
 * real presentado como completo, y de ahí un precio sugerido y un margen equivocados. Un número plausible
 * y falso es peor que la ausencia de número.
 *
 * ## Precisión
 *
 * Todo el cálculo intermedio va con `bcmath` a ocho decimales y se redondea media-arriba en cada paso
 * (nunca se trunca: truncar sesga todos los costos hacia abajo). El redondeo a los cuatro decimales de
 * almacenamiento ocurre sólo al persistir.
 */
final class CalculateArticleCost
{
    /**
     * Desgloses ya resueltos en ESTE cálculo, por artículo.
     *
     * Se memoiza el desglose completo y no sólo el costo: en un grafo en diamante —el pan y la empanada
     * usan los dos la misma masa— la primera versión calculaba la masa dos veces, una para el costo y otra
     * para las sub-líneas.
     *
     * @var array<int, CostBreakdown>
     */
    private array $memo = [];

    /**
     * Artículos en la pila de recursión actual, para la guardia de ciclos.
     *
     * @var array<int, string>
     */
    private array $stack = [];

    /**
     * Recetas ya cargadas en este cálculo. `false` significa "ya se buscó y no tiene".
     *
     * @var array<int, Recipe|false>
     */
    private array $recipes = [];

    public function __construct(private readonly UnitConverter $converter) {}

    /**
     * El desglose completo del costo de un artículo.
     *
     * @throws CostCycleDetectedException
     */
    public function breakdown(Article $article): CostBreakdown
    {
        $this->memo = [];
        $this->stack = [];
        $this->recipes = [];

        return $this->breakdownFor($article);
    }

    /**
     * Sólo el costo por unidad base, o `null` si no es calculable.
     *
     * @return numeric-string|null
     *
     * @throws CostCycleDetectedException
     */
    public function unitCost(Article $article): ?string
    {
        return $this->breakdown($article)->unitCost;
    }

    /**
     * @throws CostCycleDetectedException
     */
    private function breakdownFor(Article $article): CostBreakdown
    {
        if (array_key_exists($article->id, $this->memo)) {
            return $this->memo[$article->id];
        }

        return $this->memo[$article->id] = $this->computeBreakdown($article);
    }

    /**
     * @throws CostCycleDetectedException
     */
    private function computeBreakdown(Article $article): CostBreakdown
    {
        $recipe = $this->activeRecipeOf($article);

        if ($recipe === null) {
            // Sin receta no hay cascada: el costo es el capturado. Se devuelve un desglose de una sola
            // pieza para que el llamador no tenga que distinguir los dos casos.
            $captured = $this->capturedCostOf($article);

            return new CostBreakdown(
                articleUlid: $article->ulid,
                articleName: $article->name,
                lines: [],
                total: $captured ?? '0.00000000',
                outputQuantityInBaseUnit: '1.00000000',
                unitCost: $captured,
                missingCosts: $captured === null ? [$article->name] : [],
            );
        }

        $this->enterStack($article);

        ['lines' => $lines, 'total' => $total, 'missing' => $missing] = $this->costLines($recipe);

        $this->leaveStack($article);

        // Lo que rinde la receta, en la unidad base del artículo. `SaveRecipe` ya garantiza que las dos
        // unidades comparten magnitud, así que la conversión no puede fallar.
        $outputInBase = $recipe->outputUnit !== null && $article->baseUnit !== null
            ? $this->converter->convert($recipe->output_quantity, $recipe->outputUnit, $article->baseUnit)
            : $recipe->output_quantity;

        $unitCost = $missing === [] && bccomp($outputInBase, '0', UnitConverter::SCALE) > 0
            ? Decimal::divide($total, $outputInBase, UnitConverter::SCALE)
            : null;

        return new CostBreakdown(
            articleUlid: $article->ulid,
            articleName: $article->name,
            lines: $lines,
            total: $total,
            outputQuantityInBaseUnit: $outputInBase,
            unitCost: $unitCost,
            // Deduplicado conservando el orden: el mismo insumo sin costo puede aparecer en varias
            // sub-recetas y repetirlo en el mensaje no aporta nada.
            missingCosts: array_values(array_unique($missing)),
        );
    }

    /**
     * El desglose del costo de un MODIFICADOR (§6.1: "impacto en receta por unidad").
     *
     * «Extra queso» consume 30 g de queso, y sin costearlo el platillo con extras costaría lo mismo que sin
     * ellos — el margen del extra saldría del 100 %.
     *
     * Reutiliza la fórmula de las líneas, que es el punto: la aritmética del costeo está escrita **una sola
     * vez**. Lo que cambia es el rendimiento — una receta de modificador rinde exactamente **una aplicación**,
     * así que el costo del modificador es el total de sus líneas, sin división.
     *
     * No participa en la detección de ciclos y no la necesita: nada consume un modificador como ingrediente,
     * así que no puede formar parte de un ciclo.
     *
     * @throws CostCycleDetectedException si un componente producible arrastra recetas corruptas
     */
    public function modifierBreakdown(Modifier $modifier): CostBreakdown
    {
        $this->memo = [];
        $this->stack = [];
        $this->recipes = [];

        $recipe = Recipe::query()
            ->where('modifier_id', $modifier->id)
            ->where('status', 'active')
            ->with(['lines.component.baseUnit', 'lines.unit'])
            ->first();

        if ($recipe === null) {
            // Sin receta, un modificador no consume nada: su costo es CERO y no "desconocido". Es la
            // diferencia con un artículo sin costo capturado — «término medio» no gasta insumos, y decir que
            // su costo es incalculable haría incalculable el platillo entero.
            return new CostBreakdown(
                articleUlid: $modifier->ulid,
                articleName: $modifier->name,
                lines: [],
                total: '0.00000000',
                outputQuantityInBaseUnit: '1.00000000',
                unitCost: '0.00000000',
            );
        }

        ['lines' => $lines, 'total' => $total, 'missing' => $missing] = $this->costLines($recipe);

        return new CostBreakdown(
            articleUlid: $modifier->ulid,
            articleName: $modifier->name,
            lines: $lines,
            total: $total,
            outputQuantityInBaseUnit: '1.00000000',
            unitCost: $missing === [] ? $total : null,
            missingCosts: array_values(array_unique($missing)),
        );
    }

    /**
     * Costea las líneas de una receta, sea de artículo o de modificador.
     *
     * Extraído para que la fórmula viva en un solo sitio. Duplicarla para los modificadores habría sido la
     * forma de que las dos copias divergieran — y una de ellas invirtiendo el rendimiento pasaría inadvertida.
     *
     * @return array{lines: list<CostBreakdownLine>, total: numeric-string, missing: list<string>}
     *
     * @throws CostCycleDetectedException
     */
    private function costLines(Recipe $recipe): array
    {
        $lines = [];
        $missing = [];
        $total = '0.00000000';

        foreach ($recipe->lines as $line) {
            $component = $line->component;
            $componentBaseUnit = $component?->baseUnit;

            // No debería ocurrir: las FK son RESTRICT y el servicio valida al guardar.
            if ($component === null || $componentBaseUnit === null || $line->unit === null) {
                continue;
            }

            $quantityInBase = $this->converter->convert($line->quantity, $line->unit, $componentBaseUnit);

            $componentHasRecipe = $this->activeRecipeOf($component) !== null;

            // El desglose del componente: da las sub-líneas para abrir la cascada en pantalla y, si no es
            // calculable, la lista de lo que falta ALLÁ ABAJO. Está memoizado, así que pedirlo aquí no
            // duplica trabajo.
            $sub = $componentHasRecipe ? $this->breakdownFor($component) : null;
            $subLines = $sub?->lines ?? [];

            $componentCost = $componentHasRecipe
                ? $sub?->unitCost
                : $this->capturedCostOf($component);

            if ($componentCost === null) {
                // Se reportan las hojas que faltan, no el intermedio.
                //
                // Decir "falta el costo de Masa" es cierto y no es accionable: lo que el usuario tiene que
                // capturar es el costo de la levadura, tres niveles abajo. Sin esto, un platillo con una
                // sub-receta profunda mandaba a buscar el costo al sitio equivocado.
                $missing = [
                    ...$missing,
                    ...($sub !== null && $sub->missingCosts !== [] ? $sub->missingCosts : [$component->name]),
                ];

                $lineCost = null;
            } else {
                // El rendimiento DIVIDE (D21): 200 g utilizables al 80 % son 250 g comprados.
                $lineCost = Decimal::divide(
                    bcmul($componentCost, $quantityInBase, UnitConverter::SCALE),
                    $line->yieldDivisor(),
                    UnitConverter::SCALE,
                );

                $total = bcadd($total, $lineCost, UnitConverter::SCALE);
            }

            $lines[] = new CostBreakdownLine(
                componentUlid: $component->ulid,
                componentName: $component->name,
                quantity: $line->quantity,
                unitCode: $line->unit->code,
                quantityInComponentBaseUnit: $quantityInBase,
                componentBaseUnitCode: $componentBaseUnit->code,
                componentUnitCost: $componentCost,
                yieldPercent: $line->yield_percent,
                lineCost: $lineCost,
                componentIsProducible: $componentHasRecipe,
                subLines: $subLines,
            );
        }

        return ['lines' => $lines, 'total' => $total, 'missing' => $missing];
    }

    /**
     * @return numeric-string|null
     */
    private function capturedCostOf(Article $article): ?string
    {
        /** @var numeric-string|null */
        return ArticleCurrentCost::query()
            ->where('article_id', $article->id)
            ->value('unit_cost');
    }

    private function activeRecipeOf(Article $article): ?Recipe
    {
        if (array_key_exists($article->id, $this->recipes)) {
            return $this->recipes[$article->id] ?: null;
        }

        $recipe = Recipe::query()
            ->where('article_id', $article->id)
            ->where('status', 'active')
            // Todo lo que el cálculo necesita, en una consulta: sin esto, costear una receta de 30 líneas
            // haría más de 60 consultas para resolver componentes y unidades.
            ->with(['outputUnit', 'lines.component.baseUnit', 'lines.unit'])
            ->first();

        $this->recipes[$article->id] = $recipe ?? false;

        return $recipe;
    }

    /**
     * @throws CostCycleDetectedException
     */
    private function enterStack(Article $article): void
    {
        if (array_key_exists($article->id, $this->stack)) {
            throw CostCycleDetectedException::whileCalculating(
                [...array_values($this->stack), $article->name]
            );
        }

        $this->stack[$article->id] = $article->name;
    }

    private function leaveStack(Article $article): void
    {
        unset($this->stack[$article->id]);
    }
}

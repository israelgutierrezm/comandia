<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Domain\Exceptions\RecipeCycleException;
use App\Modules\Costing\Domain\Exceptions\RecipeInvariantException;
use App\Modules\Costing\Events\RecipeChanged;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Guarda la receta de un artículo **completa**, reemplazando la anterior.
 *
 * ## Por qué completa y no línea por línea
 *
 * Una receta es una unidad de sentido. Validar ciclos y magnitudes sobre el estado final se puede hacer
 * una vez y con certeza; con operaciones por línea, un estado intermedio inválido es inevitable —para
 * cambiar "usa harina" por "usa masa" hay un instante en que usa las dos, o ninguna— y habría que
 * decidir si se valida en ese instante. Cualquiera de las dos respuestas es mala.
 *
 * Todo en una transacción: una receta a medio reemplazar es peor que ninguna, porque produce un costo
 * plausible y equivocado.
 */
final readonly class SaveRecipe
{
    public function __construct(private RecipeCycleDetector $cycles) {}

    /**
     * @param  list<array{component_article_id: int, quantity: numeric-string, unit_id: int, yield_percent?: numeric-string, sort_order?: int}>  $lines
     *
     * @throws RecipeInvariantException
     * @throws RecipeCycleException
     */
    public function save(
        Article $article,
        array $lines,
        string $outputQuantity = '1.0000',
        ?int $outputUnitId = null,
        ?string $notes = null,
    ): Recipe {
        $outputUnitId ??= $article->base_unit_id;

        $this->assertInvariants($article, $lines, $outputUnitId);

        // La detección de ciclos va ANTES de escribir y sobre el estado POSTERIOR: un ciclo guardado
        // hace que el recálculo de costos no termine nunca (D16).
        $this->cycles->assertNoCycle(
            $article,
            array_map(fn (array $line): int => $line['component_article_id'], $lines),
        );

        $recipe = DB::transaction(function () use ($article, $lines, $outputQuantity, $outputUnitId, $notes): Recipe {
            // `updateOrCreate` sobre el artículo: el índice único (tenant, article) garantiza que hay a
            // lo más una (invariante I1), así que esto no puede crear una segunda.
            $recipe = Recipe::query()->updateOrCreate(
                ['article_id' => $article->id],
                [
                    'output_quantity' => $outputQuantity,
                    'output_unit_id' => $outputUnitId,
                    'notes' => $notes,
                    'status' => 'active',
                ],
            );

            // Se borran y se reescriben todas: es lo que significa "reemplazar". Un `sync` fino por
            // componente ahorraría dos consultas y añadiría el estado intermedio que este diseño evita.
            $recipe->lines()->delete();

            foreach ($lines as $index => $line) {
                $recipe->lines()->create([
                    'component_article_id' => $line['component_article_id'],
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'],
                    'yield_percent' => $line['yield_percent'] ?? '100.00',
                    'sort_order' => $line['sort_order'] ?? $index,
                ]);
            }

            return $recipe;
        });

        RecipeChanged::dispatch($recipe);

        return $recipe->refresh();
    }

    /**
     * Guarda la receta de un MODIFICADOR (§6.1: "impacto en receta por unidad").
     *
     * «Extra queso» consume 30 g de queso. Sin esto, el platillo con extras costaría lo mismo que sin ellos y
     * el margen del extra saldría del 100 %.
     *
     * ## Tres diferencias con la receta de un artículo, y las tres son deliberadas
     *
     * 1. **Rinde una aplicación, siempre.** `output_quantity` se fuerza a 1 y la unidad a una de dimensión
     *    `count`: un modificador no se mide en gramos, se aplica o no se aplica. Si el grupo admite cantidad
     *    —los "3 shots" de D7— es el POS quien multiplica; la receta sigue siendo por unidad.
     * 2. **No se detectan ciclos**, y no hace falta: nada consume un modificador como ingrediente, así que no
     *    puede formar parte de un ciclo.
     * 3. **No exige `is_producible`**: esa capacidad es de los artículos, y un modificador no es uno.
     *
     * Lo que SÍ comparte son los invariantes de las líneas: el componente tiene que ser insumo (I5) y la unidad
     * de la línea tiene que compartir magnitud con la unidad base del componente (I3).
     *
     * @param  list<array{component_article_id: int, quantity: numeric-string, unit_id: int, yield_percent?: numeric-string, sort_order?: int}>  $lines
     *
     * @throws RecipeInvariantException
     */
    public function saveForModifier(Modifier $modifier, array $lines, ?string $notes = null): Recipe
    {
        if ($lines === []) {
            throw RecipeInvariantException::withoutLines();
        }

        $this->assertLinesAreValid($lines);

        $recipe = DB::transaction(function () use ($modifier, $lines, $notes): Recipe {
            $recipe = Recipe::query()->updateOrCreate(
                ['modifier_id' => $modifier->id],
                [
                    'output_quantity' => '1.0000',
                    'output_unit_id' => $this->applicationUnitId(),
                    'notes' => $notes,
                    'status' => 'active',
                ],
            );

            $recipe->lines()->delete();

            foreach ($lines as $index => $line) {
                $recipe->lines()->create([
                    'component_article_id' => $line['component_article_id'],
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'],
                    'yield_percent' => $line['yield_percent'] ?? '100.00',
                    'sort_order' => $line['sort_order'] ?? $index,
                ]);
            }

            return $recipe;
        });

        RecipeChanged::dispatch($recipe);

        return $recipe->refresh();
    }

    /**
     * La unidad con la que se expresa "una aplicación" de un modificador.
     *
     * Se toma la unidad base del sistema para la dimensión `count` —la pieza—, que el alta del negocio siembra
     * (D97). No se inventa una unidad "aplicación": sería una unidad más en el selector de cada receta, para
     * expresar algo que el usuario nunca elige.
     *
     * @throws RecipeInvariantException
     */
    private function applicationUnitId(): int
    {
        $unit = Unit::query()
            ->where('dimension', UnitDimension::Count->value)
            ->orderBy('id')
            ->first();

        if ($unit === null) {
            // No debería ocurrir: el alta del negocio siembra la pieza. Si ocurre, alguien la borró, y hay que
            // decirlo en lugar de escribir una receta sin unidad.
            throw RecipeInvariantException::outputUnitMismatch('(ninguna)', 'unidad de conteo');
        }

        return $unit->id;
    }

    /**
     * Elimina la receta de un artículo.
     *
     * El artículo **no** deja de ser producible: eso es una decisión de catálogo y de otro permiso. Lo
     * que deja de existir es su composición, y con ella su costo calculable.
     */
    public function delete(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe): void {
            $recipe->lines()->delete();
            $recipe->delete();
        });

        RecipeChanged::dispatch($recipe, deleted: true);
    }

    /**
     * @param  list<array{component_article_id: int, quantity: numeric-string, unit_id: int, yield_percent?: numeric-string, sort_order?: int}>  $lines
     *
     * @throws RecipeInvariantException
     */
    private function assertInvariants(Article $article, array $lines, int $outputUnitId): void
    {
        if (! $article->is_producible) {
            throw RecipeInvariantException::articleIsNotProducible($article->name);
        }

        if ($lines === []) {
            throw RecipeInvariantException::withoutLines();
        }

        $units = Unit::query()
            ->whereIn('id', [
                $outputUnitId,
                $article->base_unit_id,
                ...array_map(fn (array $line): int => $line['unit_id'], $lines),
            ])
            ->get()
            ->keyBy('id');

        $baseUnit = $units[$article->base_unit_id] ?? null;
        $outputUnit = $units[$outputUnitId] ?? null;

        // La receta rinde en una unidad que tiene que poder convertirse a la del artículo: sin magnitud
        // común, el costo por unidad base es incalculable.
        if ($baseUnit !== null && $outputUnit !== null && $outputUnit->dimension !== $baseUnit->dimension) {
            throw RecipeInvariantException::outputUnitMismatch(
                $outputUnit->code,
                $baseUnit->code,
            );
        }

        $this->assertLinesAreValid($lines);
    }

    /**
     * Los invariantes de las LÍNEAS, comunes a las recetas de artículo y de modificador.
     *
     * Extraído para que las dos las compartan: I5 —el componente tiene que ser insumo— e I3 —la unidad de la
     * línea comparte magnitud con la unidad base del componente— valen igual sin importar quién sea el dueño.
     *
     * @param  list<array{component_article_id: int, quantity: numeric-string, unit_id: int, yield_percent?: numeric-string, sort_order?: int}>  $lines
     *
     * @throws RecipeInvariantException
     */
    private function assertLinesAreValid(array $lines): void
    {
        $units = Unit::query()
            ->whereIn('id', array_map(fn (array $line): int => $line['unit_id'], $lines))
            ->get()
            ->keyBy('id');

        $components = Article::query()
            ->whereIn('id', array_map(fn (array $line): int => $line['component_article_id'], $lines))
            ->with('baseUnit')
            ->get()
            ->keyBy('id');

        foreach ($lines as $line) {
            $component = $components[$line['component_article_id']] ?? null;

            // No debería ocurrir: el Form Request ya resolvió los ULID contra el scope de tenant. Si
            // ocurre, es un llamador interno pasando una llave inventada y conviene que reviente.
            if ($component === null) {
                throw RecipeInvariantException::componentIsNotSupply("artículo #{$line['component_article_id']}");
            }

            // Invariante I5. Es lo que hace explícita la doble modalidad de D16: un ingrediente es un
            // insumo con costo capturado, o un producible con receta propia — y en los dos casos está
            // marcado como insumo.
            if (! $component->is_supply) {
                throw RecipeInvariantException::componentIsNotSupply($component->name);
            }

            // Invariante I3. Convertir gramos a mililitros exigiría conocer la densidad del ingrediente,
            // que no es un dato del sistema de unidades sino del artículo.
            $lineUnit = $units[$line['unit_id']] ?? null;

            if ($lineUnit !== null
                && $component->baseUnit !== null
                && $lineUnit->dimension !== $component->baseUnit->dimension) {
                throw RecipeInvariantException::lineUnitMismatch(
                    $component->name,
                    $lineUnit->code,
                    $component->baseUnit->code,
                );
            }
        }
    }
}

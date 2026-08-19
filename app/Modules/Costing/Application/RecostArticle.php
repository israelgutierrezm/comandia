<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula el costo de un artículo producible y lo registra en el historial.
 *
 * ## Los costos calculados van a `article_costs`, con `origin = recipe_cascade`
 *
 * Es la recomendación de **P5**, que sigue formalmente abierta y que aplico aquí de forma explícita.
 *
 * D14 define el costo vigente como "el último costo **de adquisición**", y un platillo no se adquiere: su
 * costo se calcula. Aun así viven en la misma tabla porque el usuario quiere UNA pantalla de "cómo
 * evolucionó el costo de mis enchiladas", y partirla en dos historiales obligaría a unirlos en cada
 * lectura para siempre.
 *
 * Lo que la decisión SÍ obliga a respetar, y se respeta: el promedio del periodo de D14 se calcula sólo
 * sobre orígenes de adquisición (`ArticleCost::scopeAcquisitions`). Mezclar un costo calculado con costos
 * de compra daría un número sin significado.
 *
 * **Costo de revertir P5:** si se decide que los calculados van aparte, hay que crear la tabla, mover las
 * filas con `origin = recipe_cascade` y unir las dos en la pantalla de historial. No es gratis, pero es
 * una migración de datos acotada y no un rediseño.
 *
 * ## No escribe si el costo no cambió
 *
 * Un historial con una fila por cada recálculo que dio el mismo número es un historial que nadie puede
 * leer. Y como el recálculo se dispara en cascada, un cambio de costo de la sal generaría una fila en cada
 * artículo que la use, aunque el redondeo dejara el costo idéntico.
 */
final readonly class RecostArticle
{
    public function __construct(private CalculateArticleCost $calculator) {}

    /**
     * @param  ArticleCost|null  $sourceCost  la variación que disparó este recálculo, para la cadena causal
     * @return ArticleCost|null la fila escrita, o `null` si no había nada que escribir
     */
    public function recost(
        Article $article,
        ?ArticleCost $sourceCost = null,
        ?string $idempotencyKey = null,
    ): ?ArticleCost {
        // Sin receta activa no hay nada que recostear, y escribir sería MENTIR en la columna `origin`: el
        // motor devolvería el costo capturado y esto lo registraría como `recipe_cascade`.
        //
        // El caso llega solo: un artículo al que se le borró la receta, o uno que dejó de ser producible.
        // Su costo lo mantiene `CaptureArticleCost`, que es su dueño legítimo.
        if (! $this->hasActiveRecipe($article)) {
            return null;
        }

        $computed = $this->calculator->unitCost($article);

        if ($computed === null) {
            // No calculable: falta el costo de algún componente. NO se escribe un cero — diría que
            // producirlo es gratis— y tampoco se borra la proyección anterior: el último costo conocido
            // sigue siendo la mejor información disponible, y la pantalla de desglose es la que explica
            // qué falta.
            return null;
        }

        $rounded = Decimal::round($computed, 4);

        $current = ArticleCurrentCost::query()->where('article_id', $article->id)->first();

        if ($current !== null && Decimal::equals($current->unit_cost, $rounded, 4)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($article, $rounded, $sourceCost, $idempotencyKey): ArticleCost {
                $cost = ArticleCost::create([
                    'article_id' => $article->id,
                    'unit_cost' => $rounded,
                    'origin' => CostOrigin::RecipeCascade,
                    'source_cost_id' => $sourceCost?->id,
                    'idempotency_key' => $idempotencyKey,
                    // Sin actor: lo calculó un job y no una persona. No se inventa uno — un actor falso en
                    // un registro de evidencia es indistinguible de uno real.
                    'actor_membership_id' => null,
                    'effective_at' => CarbonImmutable::now(),
                ]);

                ArticleCurrentCost::query()->updateOrCreate(
                    ['article_id' => $article->id],
                    [
                        'unit_cost' => $cost->unit_cost,
                        'effective_at' => $cost->effective_at,
                        'source_cost_id' => $cost->id,
                    ],
                );

                return $cost->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Sin llave de idempotencia, una colisión de unicidad no puede ser este caso: es otro
            // problema y tiene que salir a la luz.
            if ($idempotencyKey === null) {
                throw $e;
            }

            // La llave ya existía: otro intento del mismo job llegó primero. Es el caso ESPERADO de un
            // re-despacho, no un error, así que no se propaga — dejar que reviente marcaría el job como
            // fallido cuando su efecto ya está aplicado.
            return null;
        }
    }

    private function hasActiveRecipe(Article $article): bool
    {
        return Recipe::query()
            ->where('article_id', $article->id)
            ->where('status', 'active')
            ->exists();
    }
}

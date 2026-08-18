<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Application\ChangeArticlePrice;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Application\SuggestPrice;
use App\Modules\Costing\Domain\PriceSuggestion;
use App\Modules\Costing\Http\Requests\ChangeArticlePriceRequest;
use Illuminate\Http\JsonResponse;

/**
 * Precio sugerido y cambio de precio (D15).
 *
 * ## Por qué el cambio de precio lo sirve `Costing` y no `Catalog`
 *
 * El precio es dato maestro del catálogo y su dueño sigue siendo `Catalog`: la escritura la hace
 * `ChangeArticlePrice`, que vive allí. Pero **historizar el cambio exige el snapshot de costeo** —costo,
 * markup y precio sugerido del momento— y `Catalog` no puede depender de `Costing` (P1): el candado lo
 * rechazaría, y declararlo crearía un ciclo el día que `Costing` necesitara escribir un precio.
 *
 * Así que el endpoint vive en el módulo que **sí** puede depender del otro. No es una casualidad de
 * fontanería: D63 define `Costing` como "recetas y costeo, **incluido el precio sugerido**", y decidir un
 * precio mirando el margen es precisamente una acción de costeo. La URL sigue colgando de `articles/{ulid}`,
 * que es el recurso que el cliente conoce.
 *
 * El permiso, en cambio, es del catálogo: `catalog.prices.update`. Quien puede cambiar precios es quien
 * administra el catálogo comercial, no quien captura costos.
 */
final class ArticlePriceController
{
    /**
     * El precio sugerido, el margen y el semáforo.
     *
     * No escribe nada. Que sea sólo lectura es la mitad de D15: el sistema sugiere y el humano decide, y un
     * servicio que sugiriera escribiendo haría imposible respetar eso.
     */
    public function show(Article $article, SuggestPrice $suggest): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($article, $suggest->for($article))]);
    }

    /**
     * Fija el precio y lo historiza con el snapshot de costeo del momento.
     */
    public function update(
        ChangeArticlePriceRequest $request,
        Article $article,
        SuggestPrice $suggest,
        ChangeArticlePrice $change,
    ): JsonResponse {
        // El snapshot se toma ANTES de escribir: es el estado del costeo con el que se tomó la decisión, y
        // tomarlo después registraría el mundo posterior al cambio.
        $suggestion = $suggest->for($article);

        $change->change(
            article: $article,
            newPrice: $request->string('price')->toString(),
            suggestedPrice: $suggestion->suggestedPrice,
            unitCost: $suggestion->unitCost,
            markupPercent: $suggestion->markupPercent,
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
        );

        // Se recalcula la sugerencia con el precio nuevo para devolver el margen y el semáforo ya
        // actualizados: el cliente acaba de cambiar el precio y lo primero que quiere ver es si quedó
        // dentro de tolerancia.
        return new JsonResponse([
            'data' => $this->present($article->refresh(), $suggest->for($article->refresh())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Article $article, PriceSuggestion $suggestion): array
    {
        return [
            'article_ulid' => $article->ulid,

            // Lo que el humano decidió.
            'current_price' => $suggestion->currentPrice,

            // Lo que el sistema sugiere. `null` cuando el costo no es calculable: NO se sugiere cero, que
            // invitaría a regalar el platillo.
            'suggested_price' => $suggestion->suggestedPrice,
            'is_computable' => $suggestion->isComputable(),
            'missing_costs' => $suggestion->missingCosts,

            // El sugerido antes de redondear, y el modo aplicado: juntos explican por qué el sugerido no es
            // exactamente costo × (1 + markup).
            'raw_suggested_price' => $suggestion->rawSuggestedPrice,
            'rounding_mode' => $suggestion->rounding->value,
            'rounding_mode_label' => $suggestion->rounding->label,

            'unit_cost' => $suggestion->unitCost,

            // MARKUP = utilidad ÷ costo. Es el porcentaje configurable con el que se sugiere (D13, §7).
            'markup_percent' => $suggestion->markupPercent,
            'markup_is_override' => $suggestion->markupIsOverride,

            // MARGEN = utilidad ÷ precio. Es lo que muestran los reportes. NO son sinónimos: con costo 100
            // y markup 200 %, el sugerido es 300 y el margen 66.67 %.
            'margin_percent' => $suggestion->marginPercent,

            // El semáforo de D15 y el umbral con el que se evaluó, para que la UI pueda explicarlo en lugar
            // de pintar un color sin justificación.
            'deviation_percent' => $suggestion->deviationPercent,
            'tolerance_percent' => $suggestion->tolerancePercent,
            'is_stale' => $suggestion->isStale,
        ];
    }
}

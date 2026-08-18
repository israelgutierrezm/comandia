<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Costing\Domain\Enums\RoundingMode;
use App\Modules\Costing\Domain\PriceSuggestion;
use App\Modules\Costing\Domain\RoundingModeDescriptor;
use App\Modules\Shared\Domain\Support\Decimal;

/**
 * Precio sugerido, margen y semáforo de precio desactualizado (D15).
 *
 * ## Las tres autoridades de D15, en orden
 *
 * El sistema **sugiere**: `sugerido = costo × (1 + markup/100)`, redondeado según la configuración.
 * El humano **decide**: este servicio no escribe nada. El precio lo fija `Catalog`.
 * El historial **recuerda**: el snapshot que este servicio produce es lo que se guarda en `price_changes`.
 *
 * Que no escriba es la parte importante del diseño: hace **imposible** que el sistema sobrescriba una
 * decisión humana, que es literalmente lo que D15 pide.
 *
 * ## MARKUP, no margen (D13, §7)
 *
 * El porcentaje configurable es markup sobre **costo**. El margen —utilidad sobre **precio**— se calcula
 * aparte y sólo para reportes. Con costo 100 y markup 200 %, el sugerido es 300 y el margen 66.67 %.
 *
 * ## El markup se resuelve por artículo y si no, por ajuste del tenant (P6)
 *
 * Dos niveles, no tres. La categoría queda **diferida con deuda declarada**: el precio es *sugerido* y el
 * humano decide, así que un default ausente cuesta una edición más por artículo, no un número equivocado.
 * Prefiero no estrenar una cascada de cuatro niveles —artículo, subcategoría, categoría, tenant— en la
 * primera iteración que la usaría.
 */
final readonly class SuggestPrice
{
    public function __construct(
        private CalculateArticleCost $calculator,
        private Settings $settings,
    ) {}

    /**
     * @param  numeric-string|null  $currentPrice  el precio vigente contra el que se compara; si se omite,
     *                                             el precio maestro del artículo
     */
    public function for(Article $article, ?string $currentPrice = null): PriceSuggestion
    {
        $breakdown = $this->calculator->breakdown($article);

        $markup = $this->markupFor($article);
        $mode = $this->roundingMode();
        $tolerance = $this->tolerancePercent();

        $price = $currentPrice ?? $article->base_price;

        if ($breakdown->unitCost === null) {
            // Sin costo no hay sugerencia. NO se sugiere cero: invitaría a regalar el platillo, y la
            // pantalla tiene que decir qué falta en lugar de mostrar un número inventado.
            return new PriceSuggestion(
                unitCost: null,
                markupPercent: $markup['percent'],
                markupIsOverride: $markup['isOverride'],
                suggestedPrice: null,
                rawSuggestedPrice: null,
                rounding: RoundingModeDescriptor::from($mode),
                currentPrice: $price,
                marginPercent: null,
                deviationPercent: null,
                tolerancePercent: $tolerance,
                isStale: false,
                missingCosts: $breakdown->missingCosts,
            );
        }

        // sugerido = costo × (1 + markup/100)
        $factor = bcadd('1', Decimal::divide($markup['percent'], '100', 8), 8);
        $raw = bcmul($breakdown->unitCost, $factor, 8);

        $suggested = $mode->apply($raw);

        return new PriceSuggestion(
            unitCost: $breakdown->unitCost,
            markupPercent: $markup['percent'],
            markupIsOverride: $markup['isOverride'],
            suggestedPrice: $suggested,
            rawSuggestedPrice: Decimal::round($raw, 4),
            rounding: RoundingModeDescriptor::from($mode),
            currentPrice: $price,
            marginPercent: $this->marginPercent($price, $breakdown->unitCost),
            deviationPercent: $this->deviationPercent($price, $suggested),
            tolerancePercent: $tolerance,
            isStale: $this->isStale($price, $suggested, $tolerance),
        );
    }

    /**
     * El MARGEN: utilidad ÷ precio (D13). Nunca se llama markup.
     *
     * @param  numeric-string|null  $price
     * @param  numeric-string  $unitCost
     * @return numeric-string|null
     */
    private function marginPercent(?string $price, string $unitCost): ?string
    {
        if ($price === null || bccomp($price, '0', 4) === 0) {
            return null;
        }

        return Decimal::divide(
            bcmul(bcsub($price, $unitCost, 8), '100', 8),
            $price,
            2,
        );
    }

    /**
     * Cuánto se desvía el precio vigente del sugerido, en porcentaje del sugerido.
     *
     * @param  numeric-string|null  $price
     * @param  numeric-string  $suggested
     * @return numeric-string|null
     */
    private function deviationPercent(?string $price, string $suggested): ?string
    {
        if ($price === null || bccomp($suggested, '0', 4) === 0) {
            return null;
        }

        return Decimal::divide(
            bcmul(bcsub($price, $suggested, 8), '100', 8),
            $suggested,
            2,
        );
    }

    /**
     * El semáforo de D15.
     *
     * Compara el **valor absoluto** de la desviación: un precio muy por debajo del sugerido está tan
     * desactualizado como uno muy por encima, y el que está por debajo es además el que cuesta dinero.
     *
     * Un artículo **sin precio** no está desactualizado: está sin precio, que es otra cosa y se ve en otro
     * lado. Marcarlo en rojo aquí llenaría el semáforo de artículos que nadie intentó cobrar todavía.
     *
     * @param  numeric-string|null  $price
     * @param  numeric-string  $suggested
     * @param  numeric-string  $tolerance
     */
    private function isStale(?string $price, string $suggested, string $tolerance): bool
    {
        if ($price === null) {
            return false;
        }

        $deviation = $this->deviationPercent($price, $suggested);

        if ($deviation === null) {
            return false;
        }

        $absolute = ltrim($deviation, '-');

        return bccomp($absolute, $tolerance, 2) > 0;
    }

    /**
     * Markup del artículo, o el del tenant si no tiene override (P6).
     *
     * @return array{percent: numeric-string, isOverride: bool}
     */
    private function markupFor(Article $article): array
    {
        if ($article->markup_percent !== null) {
            return ['percent' => $article->markup_percent, 'isOverride' => true];
        }

        return [
            'percent' => (string) $this->settings->get('pricing.default_markup_percent'),
            'isOverride' => false,
        ];
    }

    private function roundingMode(): RoundingMode
    {
        // `from()` y no `tryFrom()`: si el ajuste guardara un valor que el enum no conoce, redondear de
        // forma distinta a la configurada en silencio sería peor que fallar. El catálogo de configuración
        // valida el valor al escribirlo, así que esto no debería ocurrir nunca.
        return RoundingMode::from((string) $this->settings->get('pricing.rounding_mode'));
    }

    /**
     * @return numeric-string
     */
    private function tolerancePercent(): string
    {
        return (string) $this->settings->get('pricing.stale_price_tolerance_percent');
    }
}

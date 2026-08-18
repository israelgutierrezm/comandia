<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain;

/**
 * Lo que el sistema sugiere y lo que el humano decidió (D15).
 *
 * Las tres autoridades de D15 en un objeto: el sistema **sugiere** (`suggestedPrice`), el humano **decide**
 * (`currentPrice`), y el semáforo dice si la decisión se quedó atrás (`isStale`).
 *
 * ## MARKUP y MARGEN no son lo mismo, y aquí están los dos
 *
 * `markupPercent` = utilidad ÷ **costo**. Es el porcentaje configurable con el que se calcula el sugerido.
 * `marginPercent` = utilidad ÷ **precio**. Es lo que muestran los reportes.
 *
 * Con costo 100 y markup 200 %, el sugerido es 300 y el margen 66.67 %. Confundirlos hace que un negocio
 * crea que gana el triple de lo que gana (D13, §7).
 */
final readonly class PriceSuggestion
{
    /**
     * @param  numeric-string|null  $unitCost  `null` si el costo no es calculable
     * @param  numeric-string  $markupPercent  el aplicado: del artículo, o el del ajuste del tenant
     * @param  numeric-string|null  $suggestedPrice  ya redondeado según la configuración
     * @param  numeric-string|null  $rawSuggestedPrice  antes de redondear, para poder explicar la diferencia
     * @param  numeric-string|null  $currentPrice  el precio vigente que decidió el humano
     * @param  numeric-string|null  $marginPercent  utilidad ÷ precio VIGENTE
     * @param  numeric-string|null  $deviationPercent  cuánto se desvía el vigente del sugerido
     * @param  list<string>  $missingCosts  insumos sin costo, si el costo no es calculable
     */
    public function __construct(
        public ?string $unitCost,
        public string $markupPercent,
        public bool $markupIsOverride,
        public ?string $suggestedPrice,
        public ?string $rawSuggestedPrice,
        public RoundingModeDescriptor $rounding,
        public ?string $currentPrice,
        public ?string $marginPercent,
        public ?string $deviationPercent,
        public string $tolerancePercent,
        public bool $isStale,
        public array $missingCosts = [],
    ) {}

    /**
     * ¿Se pudo calcular una sugerencia?
     *
     * Falso cuando el costo no es calculable. En ese caso **no hay sugerencia**, y eso es distinto de
     * sugerir cero: un sugerido de cero invitaría a regalar el platillo.
     */
    public function isComputable(): bool
    {
        return $this->suggestedPrice !== null;
    }
}

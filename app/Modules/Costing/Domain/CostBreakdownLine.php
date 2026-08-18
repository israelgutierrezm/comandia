<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain;

/**
 * Una línea del desglose de costo, con todos los pasos del cálculo a la vista.
 *
 * Se guardan los intermedios —la cantidad convertida a la unidad base y el costo unitario usado— porque
 * son justamente lo que alguien quiere revisar cuando el número no le cuadra. Presentar sólo el costo de
 * la línea obligaría a rehacer la conversión a mano para verificarlo.
 */
final readonly class CostBreakdownLine
{
    /**
     * @param  numeric-string  $quantity  como se capturó, en `$unitCode`
     * @param  numeric-string  $quantityInComponentBaseUnit  tras convertir
     * @param  numeric-string|null  $componentUnitCost  costo vigente del componente, por su unidad base
     * @param  numeric-string  $yieldPercent
     * @param  numeric-string|null  $lineCost  `null` si el componente no tiene costo
     * @param  list<CostBreakdownLine>  $subLines  el desglose del componente, si es producible
     */
    public function __construct(
        public string $componentUlid,
        public string $componentName,
        public string $quantity,
        public string $unitCode,
        public string $quantityInComponentBaseUnit,
        public string $componentBaseUnitCode,
        public ?string $componentUnitCost,
        public string $yieldPercent,
        public ?string $lineCost,
        public bool $componentIsProducible = false,
        public array $subLines = [],
    ) {}
}

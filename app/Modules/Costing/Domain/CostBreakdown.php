<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain;

/**
 * El resultado de costear un artículo, línea por línea.
 *
 * Existe como objeto y no como un simple número porque **un costo sin desglose es un número que nadie
 * cree**. Es la pantalla que convence al dueño de que el sistema no se equivocó, y es lo que permite
 * responder "¿por qué subió el costo de las enchiladas?" sin abrir la base de datos.
 *
 * Inmutable y sin dependencias: se construye desde el motor de costeo y se lee.
 */
final readonly class CostBreakdown
{
    /**
     * @param  list<CostBreakdownLine>  $lines
     * @param  numeric-string  $total  costo de producir `$outputQuantityInBaseUnit` del artículo
     * @param  numeric-string  $outputQuantityInBaseUnit  lo que rinde la receta, en la unidad base del artículo
     * @param  numeric-string|null  $unitCost  costo por unidad base; `null` si no es calculable
     * @param  list<string>  $missingCosts  nombres de los componentes sin costo capturado
     */
    public function __construct(
        public string $articleUlid,
        public string $articleName,
        public array $lines,
        public string $total,
        public string $outputQuantityInBaseUnit,
        public ?string $unitCost,
        public array $missingCosts = [],
    ) {}

    /**
     * ¿Se pudo calcular el costo?
     *
     * Falso cuando algún componente —a cualquier profundidad— no tiene costo capturado. En ese caso el
     * costo **no es la suma de los que sí se conocen**: sería un número más bajo que el real, presentado
     * como si fuera completo. Ver {@see self::$missingCosts}.
     */
    public function isComputable(): bool
    {
        return $this->unitCost !== null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain;

use App\Modules\Costing\Domain\Enums\RoundingMode;

/**
 * El modo de redondeo tal como viaja al cliente: identificador estable y etiqueta para leer.
 *
 * Existe por lo mismo que D87: el identificador es el valor por el que el código compara y la etiqueta es
 * texto traducible que no puede ser llave de nada. Se expone junto al precio sugerido porque explica por qué
 * el sugerido no es exactamente costo × (1 + markup).
 */
final readonly class RoundingModeDescriptor
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    public static function from(RoundingMode $mode): self
    {
        return new self($mode->value, $mode->label());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Enums;

/**
 * De dónde salió un costo (D14: "toda variación se historiza: costo, fecha, **origen**, actor").
 *
 * La distinción no es documental. D14 define el costo vigente como "el último costo **de
 * adquisición**", y el promedio del periodo que la misma decisión pide como referencia visual tiene
 * que calcularse **sólo sobre adquisiciones**: promediar el costo calculado de un platillo con el
 * costo de compra de un insumo mezcla dos magnitudes distintas y produce un número que no significa
 * nada.
 */
enum CostOrigin: string
{
    /** Costo con el que nace el artículo, capturado en su alta. */
    case Initial = 'initial';

    /** Capturado a mano por una persona, con o sin presentación de compra. */
    case Manual = 'manual';

    /** Vino de una recepción de compra (Iteración 3). */
    case Purchase = 'purchase';

    /**
     * Lo calculó el motor de costeo desde una receta.
     *
     * Declarado pero **sin usar todavía**: el motor es el paso 6 de la iteración y P5 —si los costos
     * calculados viven en esta tabla— sigue abierta.
     */
    case RecipeCascade = 'recipe_cascade';

    /**
     * ¿Es un costo de adquisición en el sentido de D14?
     *
     * Es el filtro del promedio del periodo. Un costo calculado no se adquirió.
     */
    public function isAcquisition(): bool
    {
        return $this !== self::RecipeCascade;
    }

    /**
     * @return list<string>
     */
    public static function acquisitionValues(): array
    {
        return array_values(array_map(
            fn (self $case): string => $case->value,
            array_filter(self::cases(), fn (self $case): bool => $case->isAcquisition()),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Costo inicial',
            self::Manual => 'Captura manual',
            self::Purchase => 'Recepción de compra',
            self::RecipeCascade => 'Calculado desde receta',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}

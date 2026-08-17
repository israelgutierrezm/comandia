<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

/**
 * Dimensión física de una unidad de medida (D22).
 *
 * Catálogo **cerrado**: un tenant crea unidades, no dimensiones físicas. Las que existen son las
 * tres con las que se mide comida y bebida, y una cuarta no aparecería sin una decisión de producto.
 *
 * Cada dimensión tiene una **unidad base fija del sistema**, y todos los factores de conversión son
 * relativos a ella. Eso es lo que hace imposible una conversión inconsistente: no hay un grafo de
 * pares que pueda contradecirse, sólo un factor por unidad.
 */
enum UnitDimension: string
{
    /** Masa. Base: gramo. */
    case Mass = 'mass';

    /** Volumen. Base: mililitro. */
    case Volume = 'volume';

    /** Conteo discreto. Base: pieza. */
    case Count = 'count';

    /**
     * Código de la unidad base del sistema para esta dimensión.
     *
     * Es una constante del código y no un dato del tenant: si el tenant pudiera cambiar cuál es la
     * base, cambiaría el significado de todos los factores ya capturados.
     */
    public function baseUnitCode(): string
    {
        return match ($this) {
            self::Mass => 'g',
            self::Volume => 'ml',
            self::Count => 'pza',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mass => 'Masa',
            self::Volume => 'Volumen',
            self::Count => 'Conteo',
        };
    }

    /**
     * Etiquetas para la interfaz, indexadas por valor.
     *
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

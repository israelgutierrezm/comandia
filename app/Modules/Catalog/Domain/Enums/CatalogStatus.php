<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

/**
 * Estado de las entidades auxiliares del catálogo: unidades, categorías, presentaciones.
 *
 * Existe en `Catalog` y no se reutiliza el `OperationalStatus` de `Organization` —que sería legal,
 * porque `Organization` es kernel— para no atar el vocabulario del catálogo al de la organización.
 * "Sucursal inactiva" y "unidad inactiva" son cosas distintas que hoy comparten dos valores; el día
 * que una de las dos necesite un tercer estado, un enum compartido obligaría a que la otra lo
 * tuviera también.
 *
 * Los ARTÍCULOS no usan este enum: usan {@see ArticleStatus}, cuyo segundo valor es `archived` y no
 * `inactive`, porque un artículo con historial de precios y costos no se desactiva — se archiva
 * (D80).
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }
}

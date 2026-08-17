<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enums;

/**
 * Estado de un artículo.
 *
 * `archived` y no `inactive` a propósito, y no es sinonimia: hay historial de precios, historial de
 * costos y —desde la Iteración 4— líneas de venta apuntando a este artículo. "Archivado" significa
 * "no se puede usar desde hoy", nunca "no existió" (D80).
 */
enum ArticleStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Archived => 'Archivado',
        };
    }
}

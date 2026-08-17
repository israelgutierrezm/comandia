<?php

declare(strict_types=1);

namespace App\Modules\Organization\Domain\Enums;

/**
 * Estado de una entidad de la organización: sucursal, almacén, área o terminal.
 *
 * Dos valores y no un borrado (D80): estas entidades tienen documentos históricos
 * apuntándoles —ventas, kardex, comandas, folios— y borrarlas rompería la
 * trazabilidad. Además un `deleted_at` conviviendo con índices únicos que ya no
 * distinguen es una trampa que se descubre tarde.
 *
 * `Inactive` significa "no se puede operar aquí desde hoy", no "no existió".
 */
enum OperationalStatus: string
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
            self::Active => 'Activa',
            self::Inactive => 'Inactiva',
        };
    }
}

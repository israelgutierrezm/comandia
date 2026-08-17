<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Niveles de la cascada de configuración (ARQUITECTURA_MAESTRA §5).
 *
 * `System` no tiene tabla: el default vive EN CÓDIGO, en el catálogo. Las otras dos
 * tienen tabla propia cada una (D78).
 *
 * `maxScope` de una definición declara hasta dónde se puede sobrescribir. La tasa de
 * IVA llega a sucursal porque el negocio lo pide (§6.1); el `locale` se queda en
 * tenant porque no tiene sentido que una sucursal hable otro idioma.
 */
enum SettingScope: string
{
    /** Default en código. No se puede escribir. */
    case System = 'system';

    case Tenant = 'tenant';

    case Branch = 'branch';

    /**
     * ¿Este nivel de escritura está permitido por un `maxScope` dado?
     */
    public function isAllowedBy(self $maxScope): bool
    {
        return match ($this) {
            self::System => false,
            self::Tenant => true,
            self::Branch => $maxScope === self::Branch,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::System => 'Sistema',
            self::Tenant => 'Tenant',
            self::Branch => 'Sucursal',
        };
    }
}

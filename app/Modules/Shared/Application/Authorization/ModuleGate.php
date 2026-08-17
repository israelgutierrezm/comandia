<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Authorization;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Verificación de módulo activo (ARQUITECTURA_MAESTRA §2 regla 4, §4.2).
 *
 * "Un tenant sin e-commerce no ejecuta una sola línea de ese módulo". La regla se
 * impone en tres lugares y éste es el primero: la autorización. Los otros dos son el
 * middleware por grupo de rutas y el guard de navegación del frontend.
 *
 * ## Cómo se deduce el módulo de un permiso
 *
 * Del prefijo del nombre: `ecommerce.orders.accept` → `Ecommerce`,
 * `digital_menus.menus.manage` → `DigitalMenus`. El nombre del permiso ya lleva esa
 * información, así que consultar la columna `module` de la tabla obligaría a una
 * consulta por verificación —y las verificaciones ocurren varias veces por request—.
 *
 * ## Sólo se consultan los módulos activables
 *
 * Preguntar si el POS está activo no tiene sentido: es producto núcleo, siempre
 * activo (ESPECIFICACIÓN_MAESTRA §5). Por eso los permisos de módulos del núcleo
 * pasan sin tocar la base.
 */
final class ModuleGate
{
    private const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Cache $cache,
    ) {}

    /**
     * ¿El módulo dueño de este permiso está disponible para el tenant activo?
     */
    public function isActiveForPermission(string $permission): bool
    {
        $module = self::moduleOfPermission($permission);

        if (! in_array($module, TenantModule::activatableModules(), strict: true)) {
            // Módulo del núcleo: siempre activo.
            return true;
        }

        return $this->isEnabled($module);
    }

    /**
     * ¿El tenant activo tiene contratado este módulo activable?
     */
    public function isEnabled(string $module): bool
    {
        return in_array($module, $this->enabledModules(), strict: true);
    }

    /**
     * @throws AuthorizationDenied
     */
    public function authorizeModule(string $module): void
    {
        if (! $this->isEnabled($module)) {
            throw AuthorizationDenied::moduleNotActive($module);
        }
    }

    /**
     * @return list<string>
     */
    public function enabledModules(): array
    {
        if (! $this->context->has()) {
            return [];
        }

        return $this->cache->remember(
            "comandia.modules.tenant.{$this->context->id()}",
            self::CACHE_TTL_SECONDS,
            fn (): array => TenantModule::query()
                ->where('is_enabled', true)
                ->pluck('module')
                ->map(fn (string $module): string => $module)
                ->all(),
        );
    }

    public function forgetTenant(int $tenantId): void
    {
        $this->cache->forget("comandia.modules.tenant.{$tenantId}");
    }

    /**
     * `ecommerce.orders.accept` → `Ecommerce`
     */
    public static function moduleOfPermission(string $permission): string
    {
        return Str::studly(Str::before($permission, '.'));
    }
}

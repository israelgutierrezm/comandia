<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de permisos disponibles para armar roles (D10, §4.2).
 *
 * Devuelve los permisos **agrupados por módulo** y **filtrados por los módulos contratados**:
 * "los permisos de módulos inactivos no se muestran al tenant". Ofrecerle permisos de e-commerce
 * a quien no tiene e-commerce le haría armar un rol que no funciona, y el fallo aparecería
 * después como "no tengo permiso" sin causa aparente.
 *
 * Cada permiso viaja con su descripción en español: es el texto que lee quien arma el rol, y un
 * permiso sin explicación es un permiso que alguien marcará sin entenderlo.
 */
final class PermissionCatalogController
{
    public function __invoke(ModuleGate $modules): JsonResponse
    {
        $porModulo = Permission::groupedByModule()
            ->reject(fn ($permisos, string $module): bool => ! $this->moduleAvailable($modules, $module))
            ->map(fn ($permisos) => $permisos->map(fn (Permission $permiso): array => [
                'name' => $permiso->name,
                'description' => $permiso->description,
            ])->values());

        return new JsonResponse([
            'data' => $porModulo,
        ]);
    }

    /**
     * Un módulo del núcleo siempre está disponible; uno activable, sólo si está contratado.
     */
    private function moduleAvailable(ModuleGate $modules, string $module): bool
    {
        /** @var array<string, array{layer: string, activatable: bool, iteration: int}> $registro */
        $registro = (array) config('comandia.modules', []);

        if (! isset($registro[$module]) || $registro[$module]['activatable'] === false) {
            return true;
        }

        return $modules->isEnabled($module);
    }
}

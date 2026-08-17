<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra el catálogo cerrado de permisos (D10, D72).
 *
 * **Idempotente**: se puede correr en cada despliegue. Los permisos son catálogo del
 * sistema y su seeder está versionado, así que ésta es la vía por la que una iteración
 * introduce los permisos de su módulo.
 *
 * Los permisos son GLOBALES: no llevan `tenant_id` (excepción declarada a la Regla A),
 * así que este seeder no necesita contexto de tenant.
 *
 * Los permisos retirados se eliminan además de los roles que los tuvieran, porque una
 * fila huérfana en `role_has_permissions` apuntaría a un permiso inexistente y la
 * verificación fallaría de forma confusa.
 */
final class PermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $guard = 'web';
        $vigentes = [];

        foreach (PermissionCatalog::definitions() as $module => $permissions) {
            foreach ($permissions as $name => $description) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => $guard],
                    ['module' => $module, 'description' => $description],
                );

                $vigentes[] = $name;
            }
        }

        $retirados = Permission::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', $vigentes)
            ->get();

        foreach ($retirados as $permiso) {
            // El `cascadeOnDelete` de `role_has_permissions` limpia las asignaciones.
            $permiso->delete();
        }

        if ($retirados->isNotEmpty()) {
            $this->command?->warn(sprintf(
                'Se retiraron %d permisos que ya no están en el catálogo: %s',
                $retirados->count(),
                $retirados->pluck('name')->implode(', '),
            ));
        }

        // La cache de Spatie tiene que olvidarse: acaba de cambiar el catálogo.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Catálogo de permisos sembrado: %d permisos en %d módulos.',
            PermissionCatalog::count(),
            count(PermissionCatalog::definitions()),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los roles plantilla de un tenant (D10, D71).
 *
 * Es un servicio de aplicación y no un seeder porque ocurre **al dar de alta un
 * tenant**, no al desplegar: cada tenant nace con sus seis roles y desde ese momento son
 * suyos —los edita y los borra, salvo *Propietario*—.
 *
 * Idempotente por diseño: se puede volver a invocar sobre un tenant existente sin
 * duplicar roles. Lo que **no** hace es reponer permisos que el tenant quitó a propósito
 * de un rol editable; sobrescribirlos sería deshacer su configuración en silencio. La
 * única excepción es *Propietario*, que es de sistema y siempre se sincroniza al
 * catálogo completo.
 *
 * ## Por qué escribe el pivote directamente y no usa `syncPermissions()`
 *
 * Seis roles con hasta ~130 permisos cada uno son ~500 filas de pivote.
 * `syncPermissions()` resuelve cada nombre y, sobre todo, invalida la cache de Spatie en
 * **cada** llamada: la siguiente verificación recarga el catálogo completo desde la base,
 * seis veces por alta de tenant.
 *
 * Medido: ~2 segundos por aprovisionamiento. Eso importa fuera de las pruebas, porque el
 * alta de tenant en autoservicio (D6) ocurre con un humano esperando la respuesta.
 *
 * Escribiendo el pivote en una sola inserción y olvidando la cache **una vez al final**,
 * la misma operación baja a decenas de milisegundos. Las filas que se escriben son
 * exactamente las que escribiría Spatie; lo que se evita es su ciclo de invalidación.
 */
final readonly class ProvisionTenantRoles
{
    public function __construct(
        private TenantContext $context,
        private Authorize $authorize,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * @return array<string, Role>
     */
    public function provision(): array
    {
        $tenantId = $this->context->id();
        $guard = 'web';

        $roles = DB::transaction(function () use ($tenantId, $guard): array {
            $permissionIds = $this->permissionIdsByName($guard);
            $created = [];

            foreach (RoleTemplates::definitions() as $name => $template) {
                $existing = Role::query()
                    ->where('name', $name)
                    ->where('guard_name', $guard)
                    ->first();

                $isNew = $existing === null;

                $role = $existing ?? Role::create([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'guard_name' => $guard,
                    'description' => $template['description'],
                    'requires_two_factor' => $template['requires_two_factor'],
                ]);

                // `is_system` no es asignable en masa a propósito: nadie debe poder
                // promover un rol a rol de sistema desde una petición. Aquí se escribe
                // directamente, porque este servicio ES la autoridad que lo define.
                if ($isNew && $template['is_system']) {
                    $role->is_system = true;
                    $role->save();
                }

                // Sólo se sincronizan los permisos en el alta, o siempre si es rol de
                // sistema. Reponerlos en un rol editable desharía la configuración que
                // el tenant hizo a conciencia.
                if ($isNew || $template['is_system']) {
                    $this->writePivot(
                        $role,
                        $template['permissions'] ?? PermissionCatalog::names(),
                        $permissionIds,
                    );
                }

                $created[$name] = $role;
            }

            return $created;
        });

        // Una sola invalidación al final, en lugar de una por rol.
        $this->registrar->forgetCachedPermissions();

        foreach ($roles as $role) {
            $this->authorize->forgetRole($role);
        }

        return $roles;
    }

    /**
     * @return array<string, int> nombre del permiso => id
     */
    private function permissionIdsByName(string $guard): array
    {
        /** @var array<string, int> $ids */
        $ids = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('id', 'name')
            ->all();

        $faltantes = array_diff(PermissionCatalog::names(), array_keys($ids));

        if ($faltantes !== []) {
            // Falla ruidosamente en lugar de crear roles a medias: un rol al que le
            // faltan permisos es un rol que no puede operar, y el síntoma aparecería
            // días después como "no tengo permiso" sin causa aparente.
            throw new RuntimeException(sprintf(
                'El catálogo de permisos no está sembrado por completo. Faltan: %s. '
                .'Corre `php artisan db:seed --class=Database\\Seeders\\PermissionCatalogSeeder`.',
                implode(', ', array_slice($faltantes, 0, 5)).(count($faltantes) > 5 ? '…' : ''),
            ));
        }

        return $ids;
    }

    /**
     * @param  list<string>  $permissionNames
     * @param  array<string, int>  $permissionIds
     */
    private function writePivot(Role $role, array $permissionNames, array $permissionIds): void
    {
        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();

        $rows = [];

        foreach ($permissionNames as $name) {
            $rows[] = [
                'role_id' => $role->id,
                'permission_id' => $permissionIds[$name],
            ];
        }

        if ($rows !== []) {
            DB::table('role_has_permissions')->insert($rows);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Authorization;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Autorización por contexto — el único camino permitido para verificar un permiso
 * (ARQUITECTURA_MAESTRA §4.2, D9).
 *
 * ## Por qué no `$user->can()`
 *
 * Spatie **suma** los permisos de todos los roles del usuario en el tenant. Comandia
 * opera bajo un único **rol activo**: un mesero que además es gerente y está operando
 * como mesero no debe poder cancelar un platillo comandado. Usar `$user->can()`
 * concedería permisos que el rol activo no tiene, y lo haría en silencio.
 *
 * Un test estructural falla si `->can(`, `Gate::allows`, `@can` o `hasPermissionTo`
 * aparecen fuera de este módulo. Una regla que sólo vive en la revisión de código se
 * erosiona.
 *
 * ## El algoritmo, en orden
 *
 *   1. El módulo dueño del permiso está activo para el tenant. Si no: falso, sin
 *      importar el rol. Un tenant sin e-commerce no ejecuta ni autoriza su código.
 *   2. El **rol activo** tiene el permiso. Nunca la suma de roles.
 *   3. Si la acción es de sucursal, la sucursal activa está en el alcance de la
 *      membresía.
 *
 * El orden importa: el paso 1 es el más barato y el que corta más casos.
 */
final class Authorize
{
    /**
     * Vida de la cache de permisos por rol. Corta a propósito: los cambios de rol se
     * invalidan explícitamente, y este TTL es sólo la red por si algún camino de
     * escritura se olvidara de invalidar.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly ContextHolder $holder,
        private readonly Cache $cache,
        private readonly ModuleGate $modules,
    ) {}

    /**
     * ¿El contexto actual puede ejercer este permiso?
     */
    public function can(string $permission, ?int $branchId = null): bool
    {
        if (! $this->holder->has()) {
            return false;
        }

        $context = $this->holder->get();

        // Las superficies públicas no ejercen permisos: no hay quién los ejerza.
        if ($context->isPublic || ! $context->isAuthenticated()) {
            return false;
        }

        if (! $this->modules->isActiveForPermission($permission)) {
            return false;
        }

        $role = $context->activeRole;

        if ($role === null) {
            return false;
        }

        if (! $this->roleHasPermission($role, $permission)) {
            return false;
        }

        return $this->withinBranchScope($context, $branchId);
    }

    /**
     * Igual que {@see self::can()} pero lanza 403.
     *
     * @throws AuthorizationDenied
     */
    public function authorize(string $permission, ?int $branchId = null): void
    {
        if (! $this->can($permission, $branchId)) {
            throw AuthorizationDenied::forPermission($permission);
        }
    }

    /**
     * Verificación para acciones de escritura: además del permiso, exige que el tenant
     * admita escrituras.
     *
     * Existe como método aparte y no como bandera para que en el código quede visible
     * qué casos de uso escriben. Un tenant en `read_only` por impago tiene que poder
     * consultar y exportar sus datos, y no poder cobrar.
     *
     * @throws AuthorizationDenied
     */
    public function authorizeWrite(string $permission, ?int $branchId = null): void
    {
        $context = $this->holder->get();

        if ($context->isReadOnly) {
            throw AuthorizationDenied::readOnlyTenant();
        }

        $this->authorize($permission, $branchId);
    }

    /**
     * Permisos del rol activo. Los consume el shell del frontend para pintar la
     * navegación y la directiva `v-can`.
     *
     * @return list<string>
     */
    public function permissionsOfActiveRole(): array
    {
        if (! $this->holder->has()) {
            return [];
        }

        $role = $this->holder->get()->activeRole;

        if ($role === null) {
            return [];
        }

        return array_values(array_filter(
            $this->cachedPermissionNames($role),
            fn (string $permission): bool => $this->modules->isActiveForPermission($permission),
        ));
    }

    // -----------------------------------------------------------------
    // Interno
    // -----------------------------------------------------------------

    private function roleHasPermission(Role $role, string $permission): bool
    {
        return in_array($permission, $this->cachedPermissionNames($role), strict: true);
    }

    /**
     * @return list<string>
     */
    private function cachedPermissionNames(Role $role): array
    {
        return $this->cache->remember(
            self::cacheKey($role),
            self::CACHE_TTL_SECONDS,
            fn (): array => $role->permissionNames(),
        );
    }

    /**
     * Invalidación al editar un rol o sus permisos.
     */
    public function forgetRole(Role $role): void
    {
        $this->cache->forget(self::cacheKey($role));
    }

    private static function cacheKey(Role $role): string
    {
        // La llave incluye el tenant aunque el id del rol ya sea único: hace que la
        // clave sea legible en Redis al depurar y que una purga por tenant sea posible.
        return "comandia.authz.tenant.{$role->tenant_id}.role.{$role->id}";
    }

    private function withinBranchScope(RequestContext $context, ?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }

        return $context->requireMembership()->canOperateInBranch($branchId);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Application\Context\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El contexto operativo, tal como lo consumen la SPA y la app Flutter.
 *
 * Es la respuesta a "¿quién soy y qué puedo hacer aquí?", y la única fuente de la que
 * el frontend debe sacar los permisos para pintar navegación y aplicar `v-can`.
 *
 * Sólo expone identificadores públicos (ULID), nunca las PK internas
 * (ARQUITECTURA_MAESTRA §7).
 *
 * Los permisos que devuelve son los del **rol activo** y ya vienen filtrados por
 * módulo contratado: la navegación no debe ofrecer un botón que al pulsarlo dé 403.
 *
 * @mixin RequestContext
 */
final class RequestContextResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RequestContext $context */
        $context = $this->resource;

        $membership = $context->membership;

        return [
            'tenant' => [
                'ulid' => $context->tenant->ulid,
                'name' => $context->tenant->name,
                'slug' => $context->tenant->slug,
                'status' => $context->tenant->status->value,
            ],

            'membership' => $membership === null ? null : [
                'ulid' => $membership->ulid,
                // El nombre resuelto según D66: perfil de empleado sobre usuario.
                'display_name' => app(MembershipNameResolver::class)->resolve($membership)->short(),
                'full_name' => app(MembershipNameResolver::class)->resolve($membership)->full(),
                'has_all_branches' => $membership->has_all_branches,
                'has_pin' => $membership->hasPin(),
            ],

            'active_role' => $context->activeRole === null ? null : [
                'ulid' => $context->activeRole->ulid,
                'name' => $context->activeRole->name,
                'requires_two_factor' => $context->activeRole->requires_two_factor,
            ],

            // Los roles que la persona TIENE asignados, para poder cambiar de rol activo.
            //
            // Esto no contradice D9, y la distinción es la que importa: listar los roles asignados
            // no es sumar sus permisos. Los permisos que viajan arriba siguen siendo los de UN rol
            // —el activo—, y el cambio se hace eligiendo entre esta lista, que es exactamente lo
            // que el middleware revalida contra el pivote al recibir `X-Role`.
            //
            // Sin esto, el selector de rol del shell sólo podía ofrecer el rol ya activo: un
            // selector de una sola opción, inútil justo en el producto donde el rol activo decide
            // todo.
            'assigned_roles' => $this->assignedRoles($context),

            'active_branch' => $context->activeBranch === null ? null : [
                'ulid' => $context->activeBranch->ulid,
                'name' => $context->activeBranch->name,
                'code' => $context->activeBranch->code,
                // La zona horaria viaja al cliente porque los "del día" se presentan en
                // la hora de la sucursal, no del navegador (§7).
                'timezone' => $context->activeBranch->timezone,
            ],

            // Las sucursales ENTRE LAS QUE la persona puede elegir su sucursal activa: las de su
            // alcance (todas las activas si `has_all_branches`, o las de su ámbito). Va aquí —en
            // «¿quién soy?»— y no se toma de `GET /branches`, que es la lista de ADMINISTRACIÓN y exige
            // `organization.branches.view`: un mesero o cajero no administra sucursales pero sí necesita
            // decir en cuál está operando. Sin esto, su selector quedaba vacío y no podían entrar al POS.
            'branches' => $this->reachableBranches($context),

            'terminal' => $context->terminal === null ? null : [
                'ulid' => $context->terminal->ulid,
                'name' => $context->terminal->name,
            ],

            'is_read_only' => $context->isReadOnly,

            'permissions' => app(Authorize::class)->permissionsOfActiveRole(),

            'active_modules' => app(ModuleGate::class)->enabledModules(),
        ];
    }

    /**
     * Las sucursales en las que la membresía puede operar, para el selector de sucursal activa.
     *
     * Acotadas al alcance de la membresía (`scopedBranchIds`, que ya expande `has_all_branches` a
     * todas las activas) y filtradas a activas: no se elige como sucursal activa una archivada. El
     * orden por nombre es para que el selector sea estable.
     *
     * @return list<array{ulid: string, name: string, code: string, timezone: string}>
     */
    private function reachableBranches(RequestContext $context): array
    {
        $membership = $context->membership;

        if ($membership === null) {
            return [];
        }

        $ids = $membership->scopedBranchIds();

        if ($ids === []) {
            return [];
        }

        return Branch::query()
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'ulid' => $branch->ulid,
                'name' => $branch->name,
                'code' => $branch->code,
                'timezone' => $branch->timezone,
            ])
            ->all();
    }

    /**
     * Roles asignados a la persona en este negocio.
     *
     * Se consulta el pivote a través de la relación `roles()` de Spatie —permitida: es pertenencia,
     * no verificación (ver el bloque de documentación de `User`)—. No se usa `hasRole()` ni
     * `getAllPermissions()`, que razonan sobre la suma de roles.
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function assignedRoles(RequestContext $context): array
    {
        $user = $context->membership?->user;

        if ($user === null) {
            return [];
        }

        return $user->roles()
            ->orderBy('name')
            ->get()
            ->map(fn ($role): array => [
                'ulid' => $role->ulid,
                'name' => $role->name,
            ])
            ->values()
            ->all();
    }
}

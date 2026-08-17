<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Http\Requests\StoreRoleRequest;
use App\Modules\Identity\Http\Requests\UpdateRoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Administración de los roles del tenant (D10).
 *
 * El tenant combina permisos del catálogo cerrado en roles propios. Lo que no puede es tocar el
 * rol *Propietario*, que es de sistema.
 */
final class RoleController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Authorize $authorize,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Role>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [],
            sortable: ['name', 'created_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        $roles = $query
            ->apply(Role::query()->with('permissions')->withMembersCount(), $request)
            ->paginate($query->perPage($request));

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->string('name')->toString(),
            'guard_name' => 'web',
            'description' => $request->input('description'),
            'requires_two_factor' => $request->boolean('requires_two_factor'),
        ]);

        /** @var list<string> $permisos */
        $permisos = array_values((array) $request->input('permissions', []));

        $role->syncPermissions($permisos);
        $this->authorize->forgetRole($role);

        $this->audit->log(
            action: AuditAction::ROLE_CREATED,
            auditable: $role,
            after: ['name' => $role->name, 'permissions' => $permisos],
        );

        return (new RoleResource($role->load('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        // `loadCount` sobre la INSTANCIA sí funciona: aquí `guard_name` está poblado y la relación
        // de Spatie puede resolver su modelo. El problema es sólo con el query builder.
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->rejectSystemRole($role, 'El rol Propietario no se puede editar: por definición tiene todos los permisos (D10).');

        $antes = [
            'name' => $role->name,
            'permissions' => $role->permissionNames(),
        ];

        $role->update($request->safe()->except('permissions'));

        if ($request->has('permissions')) {
            /** @var list<string> $permisos */
            $permisos = array_values((array) $request->input('permissions', []));

            $role->syncPermissions($permisos);

            // Invalidación obligatoria: `Authorize` cachea los permisos por rol, así que sin
            // esto un permiso recién quitado seguiría concediéndose hasta que expirara la cache
            // —y quitar un permiso suele hacerse con urgencia—.
            $this->authorize->forgetRole($role);
        }

        $this->audit->log(
            action: AuditAction::ROLE_UPDATED,
            auditable: $role,
            before: $antes,
            after: ['name' => $role->name, 'permissions' => $role->fresh()->permissionNames()],
        );

        return new RoleResource($role->refresh()->load('permissions')->loadCount('users'));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->rejectSystemRole($role, 'El rol Propietario no se puede eliminar (D10).');

        $miembros = $role->users()->count();

        if ($miembros > 0) {
            // Borrarlo dejaría a esas personas sin rol y, si era su rol por defecto, sin poder
            // operar. Es mejor obligar a reasignar: la alternativa es que alguien descubra que no
            // puede entrar en plena hora pico.
            throw new ConflictHttpException(sprintf(
                'No se puede eliminar este rol: %d persona(s) lo tienen asignado. Reasígnalas primero.',
                $miembros,
            ));
        }

        $nombre = $role->name;
        $permisos = $role->permissionNames();

        $this->authorize->forgetRole($role);

        $this->audit->log(
            action: AuditAction::ROLE_DELETED,
            auditable: $role,
            before: ['name' => $nombre, 'permissions' => $permisos],
        );

        $role->delete();

        return new JsonResponse(status: 204);
    }

    private function rejectSystemRole(Role $role, string $message): void
    {
        if ($role->isProtected()) {
            throw new ConflictHttpException($message);
        }
    }
}

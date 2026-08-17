<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Http\Requests\SyncMembershipRolesRequest;
use App\Modules\Identity\Http\Resources\MembershipResource;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Asignación de roles a una membresía (D9).
 */
final class MembershipRoleController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContextHolder $holder,
    ) {}

    public function sync(
        SyncMembershipRolesRequest $request,
        TenantMembership $membership,
    ): MembershipResource {
        // Nadie edita sus propios roles. Dos razones, y la segunda es la que importa: evita el
        // auto-bloqueo —quitarse el permiso de administrar roles y no poder devolvérselo— y
        // evita la escalada silenciosa, porque quien puede asignar roles podría concederse
        // cualquier permiso del catálogo sin que nadie más participe. Con esto, ampliar los
        // propios permisos siempre exige a otra persona, y eso queda en la bitácora.
        if ($this->holder->get()->requireMembership()->id === $membership->id) {
            throw new ConflictHttpException(
                'No puedes cambiar tus propios roles: pídelo a otra persona con permiso para '
                .'asignarlos.'
            );
        }

        if (! $membership->hasCredentials()) {
            // Los roles se asignan al usuario (teams de Spatie = tenant), así que una membresía
            // sin credenciales no puede tenerlos. Es coherente: quien no inicia sesión no ejerce
            // permisos.
            throw new ConflictHttpException(
                'Una persona sin credenciales de acceso no puede tener roles: no inicia sesión.'
            );
        }

        /** @var list<string> $ulids */
        $ulids = array_values((array) $request->input('role_ulids', []));

        $user = $membership->user;

        $antes = $user?->roles()->pluck('name')->all() ?? [];

        // Se resuelven en el orden recibido: el primero queda como rol por defecto, y ése es el
        // que el operador verá activo al entrar.
        $roles = collect($ulids)
            ->map(fn (string $ulid): ?Role => Role::findByUlid($ulid))
            ->filter()
            ->values();

        $user?->syncRoles($roles->all());

        $membership->update(['default_role_id' => $roles->first()?->id]);

        $this->audit->log(
            action: AuditAction::ROLES_ASSIGNED,
            auditable: $membership,
            before: ['roles' => $antes],
            after: ['roles' => $roles->pluck('name')->all()],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        );
    }
}

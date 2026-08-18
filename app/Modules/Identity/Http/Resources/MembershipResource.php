<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantMembership
 */
final class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $names = app(MembershipNameResolver::class);

        /** @var TenantMembership $membership */
        $membership = $this->resource;

        return [
            'ulid' => $this->ulid,

            // Nombre resuelto según D66: perfil de empleado sobre usuario. El cliente nunca
            // decide de dónde sale el nombre; para eso existe un solo resolutor.
            'display_name' => $names->resolve($membership)->short(),
            'full_name' => $names->resolve($membership)->full(),

            'employee_code' => $this->employee_code,
            'status' => $this->status->value,

            // Distingue al empleado sin credenciales —el lavaloza en nómina que jamás inicia
            // sesión— de quien sí opera el sistema (§4.1).
            'has_credentials' => $this->hasCredentials(),
            'email' => $this->user?->email,

            // Sólo si tiene PIN, nunca el PIN ni su hash.
            'has_pin' => $this->hasPin(),
            'pin_locked' => $this->isPinLocked(),

            'has_all_branches' => $this->has_all_branches,

            'default_role' => $this->whenLoaded('defaultRole', fn () => $this->defaultRole === null ? null : [
                'ulid' => $this->defaultRole->ulid,
                'name' => $this->defaultRole->name,
            ]),

            // TODOS los roles de la persona, no sólo el activo por omisión.
            //
            // El rol por defecto dice con cuál entra; esta lista dice entre cuáles puede elegir, y son
            // dos preguntas distintas. Sin ella, la pantalla que administra roles no puede mostrar el
            // estado actual: sabría cuál está activo y no cuáles tiene.
            //
            // Viven en el USUARIO —los roles de Spatie usan el tenant como equipo— así que una membresía
            // sin credenciales devuelve la lista vacía, que es correcto: quien no inicia sesión no
            // ejerce permisos.
            'roles' => $this->when(
                $this->relationLoaded('user') && $this->user?->relationLoaded('roles') === true,
                fn () => $this->user->roles
                    ->map(fn ($role): array => ['ulid' => $role->ulid, 'name' => $role->name])
                    ->values(),
            ),

            'branch_scopes' => $this->whenLoaded(
                'branchScopes',
                fn () => $this->branchScopes
                    ->map(fn ($scope) => [
                        'ulid' => $scope->branch->ulid,
                        'name' => $scope->branch->name,
                    ])
                    ->values(),
            ),

            'has_employee_profile' => $this->employeeProfile !== null,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

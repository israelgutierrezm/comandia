<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Resources;

use App\Modules\Identity\Application\MembershipNameResolver;
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

            'active_branch' => $context->activeBranch === null ? null : [
                'ulid' => $context->activeBranch->ulid,
                'name' => $context->activeBranch->name,
                'code' => $context->activeBranch->code,
                // La zona horaria viaja al cliente porque los "del día" se presentan en
                // la hora de la sucursal, no del navegador (§7).
                'timezone' => $context->activeBranch->timezone,
            ],

            'terminal' => $context->terminal === null ? null : [
                'ulid' => $context->terminal->ulid,
                'name' => $context->terminal->name,
            ],

            'is_read_only' => $context->isReadOnly,

            'permissions' => app(Authorize::class)->permissionsOfActiveRole(),

            'active_modules' => app(ModuleGate::class)->enabledModules(),
        ];
    }
}

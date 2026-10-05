<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Application\CreateMembership;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Http\Requests\StoreMembershipRequest;
use App\Modules\Identity\Http\Requests\UpdateMembershipRequest;
use App\Modules\Identity\Http\Resources\MembershipResource;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use App\Modules\Tenancy\Application\TenantLimits;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Administración del personal del tenant.
 *
 * Contiene el candado contra auto-bloqueo: nadie se suspende a sí mismo. Sin él, el propietario
 * de un negocio con un solo administrador puede quedarse fuera de su propio sistema con un clic,
 * y recuperarlo exigiría intervención en base de datos.
 */
final class MembershipController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContextHolder $holder,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, TenantMembership>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['employee_code', 'created_at'],
            searchable: ['employee_code'],
            defaultSort: 'employee_code',
        );

        // Carga previa obligatoria: el nombre se resuelve desde `user` o `employeeProfile`
        // (D66), así que sin esto `preventLazyLoading` lanzaría — y con listados de personal
        // sería un N+1 de dos consultas por fila.
        $memberships = $query
            ->apply(
                TenantMembership::query()->with(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation']),
                $request,
            )
            ->paginate($query->perPage($request));

        return MembershipResource::collection($memberships);
    }

    public function store(StoreMembershipRequest $request, CreateMembership $create): JsonResponse
    {
        /** @var array<string, mixed>|null $perfil */
        $perfil = $request->input('employee_profile');

        $alta = $create->create(
            email: $request->input('email'),
            firstName: $request->string('first_name')->toString(),
            paternalSurname: $request->string('paternal_surname')->toString(),
            maternalSurname: $request->input('maternal_surname'),
            employeeCode: $request->input('employee_code'),
            roleUlids: array_values((array) $request->input('role_ulids', [])),
            branchUlids: array_values((array) $request->input('branch_ulids', [])),
            hasAllBranches: $request->boolean('has_all_branches'),
            employeeProfile: $perfil,
        );

        $membership = $alta->membership;

        $this->audit->log(
            action: AuditAction::USER_CREATED,
            auditable: $membership,
            after: [
                'employee_code' => $membership->employee_code,
                'invited' => $alta->invitation !== null,
                'status' => $membership->status->value,
            ],
        );

        return (new MembershipResource(
            $membership->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        ))
            // El enlace de la invitación, UNA vez, para copiarlo si el correo no llega (decisión 7 del diseño).
            ->additional(['meta' => ['invitation_link' => $alta->invitation?->link]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(TenantMembership $membership): MembershipResource
    {
        // `user.roles` sólo en el detalle y no en el listado: es la consulta que la pantalla de
        // administración de roles necesita, y en un listado de cincuenta personas sería una consulta por
        // fila para un dato que la tabla no muestra.
        return new MembershipResource(
            $membership->load(['user.roles', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        );
    }

    public function update(UpdateMembershipRequest $request, TenantMembership $membership): MembershipResource
    {
        // Sólo datos de la persona: el alcance por sucursal tiene su endpoint, su permiso y su asiento.
        $before = $membership->only(['employee_code']);

        $membership->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::USER_UPDATED,
            auditable: $membership,
            before: $before,
            after: $membership->only(['employee_code']),
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        );
    }

    public function suspend(TenantMembership $membership): MembershipResource
    {
        $this->rejectSelf($membership, 'No puedes suspenderte a ti mismo.');

        $before = ['status' => $membership->status->value];

        $membership->update(['status' => MembershipStatus::Suspended]);

        // Los tokens de esa persona en este tenant dejan de servir: el middleware revalida la
        // membresía en cada petición, así que la suspensión surte efecto de inmediato. Borrarlos
        // además evita que un token vivo siga presentándose y generando 403 en los logs.
        $membership->user?->tokens()->where('tenant_id', $membership->tenantId())->delete();

        // Y su invitación pendiente deja de servir: una persona suspendida no entra aceptando el enlace que ya tenía.
        MembershipInvitation::query()
            ->where('membership_id', $membership->id)
            ->open()
            ->update(['revoked_at' => now()]);

        $this->audit->log(
            action: AuditAction::USER_SUSPENDED,
            auditable: $membership,
            before: $before,
            after: ['status' => MembershipStatus::Suspended->value],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        );
    }

    public function reactivate(TenantMembership $membership, TenantLimits $limits): MembershipResource
    {
        // Una invitada se activa al ACEPTAR su invitación, no porque alguien lo decida (diseño de acceso, fase 3):
        // activarla sin su consentimiento era sumarla al negocio sin preguntarle, y ni siquiera probaba que el correo
        // fuera suyo.
        if ($membership->status === MembershipStatus::Invited) {
            throw new ConflictHttpException(
                'Una persona invitada se activa al aceptar su invitación. Si no le llegó, reenvíasela.',
            );
        }

        // Reactivar vuelve a ocupar plaza del plan (D4 mide las activas): no puede rebasarla. Sin esto, suspender a
        // alguien, dar de alta a otro y reactivar al primero dejaba al negocio por encima de su límite.
        if ($membership->status !== MembershipStatus::Active && ! $limits->allows(TenantLimitKey::MaxUsers)) {
            throw new ConflictHttpException(sprintf(
                'Alcanzaste el límite de %d usuario(s) de tu plan. Da de baja a alguien o contacta a soporte para ampliarlo.',
                (int) $limits->limit(TenantLimitKey::MaxUsers),
            ));
        }

        $before = ['status' => $membership->status->value];

        $membership->update(['status' => MembershipStatus::Active]);

        $this->audit->log(
            action: AuditAction::USER_REACTIVATED,
            auditable: $membership,
            before: $before,
            after: ['status' => MembershipStatus::Active->value],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        );
    }

    /**
     * Candado contra auto-bloqueo.
     *
     * No es paternalismo: en un negocio con un solo administrador, permitirlo significa que un
     * clic deja el sistema inaccesible y la recuperación exige tocar la base de datos.
     */
    private function rejectSelf(TenantMembership $membership, string $message): void
    {
        if ($this->holder->get()->requireMembership()->id === $membership->id) {
            throw new ConflictHttpException($message);
        }
    }
}

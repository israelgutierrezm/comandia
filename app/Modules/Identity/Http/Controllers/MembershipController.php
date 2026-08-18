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
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
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
                TenantMembership::query()->with(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch']),
                $request,
            )
            ->paginate($query->perPage($request));

        return MembershipResource::collection($memberships);
    }

    public function store(StoreMembershipRequest $request, CreateMembership $create): JsonResponse
    {
        /** @var array<string, mixed>|null $perfil */
        $perfil = $request->input('employee_profile');

        $membership = $create->create(
            email: $request->input('email'),
            plainPassword: $request->input('password'),
            firstName: $request->string('first_name')->toString(),
            paternalSurname: $request->string('paternal_surname')->toString(),
            maternalSurname: $request->input('maternal_surname'),
            employeeCode: $request->input('employee_code'),
            roleUlids: array_values((array) $request->input('role_ulids', [])),
            branchUlids: array_values((array) $request->input('branch_ulids', [])),
            hasAllBranches: $request->boolean('has_all_branches'),
            employeeProfile: $perfil,
        );

        $this->audit->log(
            action: AuditAction::USER_CREATED,
            auditable: $membership,
            after: [
                'employee_code' => $membership->employee_code,
                'has_credentials' => $membership->hasCredentials(),
                'status' => $membership->status->value,
            ],
        );

        return (new MembershipResource(
            $membership->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        ))->response()->setStatusCode(201);
    }

    public function show(TenantMembership $membership): MembershipResource
    {
        // `user.roles` sólo en el detalle y no en el listado: es la consulta que la pantalla de
        // administración de roles necesita, y en un listado de cincuenta personas sería una consulta por
        // fila para un dato que la tabla no muestra.
        return new MembershipResource(
            $membership->load(['user.roles', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        );
    }

    public function update(UpdateMembershipRequest $request, TenantMembership $membership): MembershipResource
    {
        $before = $membership->only(['employee_code', 'has_all_branches']);

        $membership->update($request->safe()->all());

        $this->audit->log(
            action: AuditAction::USER_CREATED,
            auditable: $membership,
            before: $before,
            after: $membership->only(['employee_code', 'has_all_branches']),
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
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

        $this->audit->log(
            action: AuditAction::USER_SUSPENDED,
            auditable: $membership,
            before: $before,
            after: ['status' => MembershipStatus::Suspended->value],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        );
    }

    public function reactivate(TenantMembership $membership): MembershipResource
    {
        $before = ['status' => $membership->status->value];

        $membership->update(['status' => MembershipStatus::Active]);

        $this->audit->log(
            action: AuditAction::USER_SUSPENDED,
            auditable: $membership,
            before: $before,
            after: ['status' => MembershipStatus::Active->value],
        );

        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
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

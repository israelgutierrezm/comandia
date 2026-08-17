<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Selección de negocio: "URL única con selección de tenant al login" (ESPECIFICACIÓN_MAESTRA §2).
 *
 * Es también el punto donde se cambia de negocio sin cerrar sesión, que es lo que necesita quien
 * administra dos restaurantes: la identidad es global y la pertenencia es por tenant (§4.1).
 *
 * La lista sale de `membershipsAcrossTenants()`, la única relación del proyecto que consulta sin
 * scope de tenant a propósito: aquí todavía no hay contexto, y precisamente decidirlo es lo que se
 * está haciendo.
 */
final class TenantSelectionController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/SelectTenant', [
            'tenants' => $this->availableTenants($request),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            ['tenant_ulid' => ['required', 'string', 'size:26']],
            ['tenant_ulid.required' => 'Elige un negocio para continuar.'],
        );

        /** @var User $user */
        $user = $request->user();

        // Se busca ENTRE SUS MEMBRESÍAS y no entre los tenants: si se resolviera el tenant por su
        // ULID y luego se comprobara la pertenencia, un ULID ajeno confirmaría que ese negocio
        // existe. Partiendo de sus membresías, un ULID ajeno simplemente no aparece.
        $membership = $user->membershipsAcrossTenants()
            ->where('status', 'active')
            ->with('tenant')
            ->get()
            ->first(fn (TenantMembership $m): bool => $m->tenant?->ulid === $validated['tenant_ulid']);

        if ($membership === null) {
            throw ValidationException::withMessages([
                'tenant_ulid' => ['No tienes acceso a ese negocio.'],
            ]);
        }

        $anterior = $request->session()->get('tenant_id');

        $request->session()->put('tenant_id', (int) $membership->tenantId());

        app(TenantContext::class)->runFor(
            (int) $membership->tenantId(),
            fn () => $this->audit->log(
                // Entrar por primera vez es un login; cambiar de negocio con la sesión abierta es
                // otra cosa, y la bitácora del negocio nuevo debe poder distinguirlas.
                action: $anterior === null ? AuditAction::LOGIN : AuditAction::BRANCH_SWITCHED,
                auditable: $membership,
                // Entrar a un negocio ocurre antes de que exista contexto de ese negocio: sin actor
                // explícito el asiento quedaría atribuido a «Sistema».
                actor: $membership,
            ),
        );

        return redirect()->route('admin.dashboard');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availableTenants(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $names = app(MembershipNameResolver::class);

        return $user->membershipsAcrossTenants()
            ->where('status', 'active')
            ->with(['tenant', 'user', 'employeeProfile'])
            ->get()
            ->filter(fn (TenantMembership $m): bool => $m->tenant !== null && $m->tenant->allowsAccess())
            ->map(fn (TenantMembership $m): array => [
                'ulid' => $m->tenant->ulid,
                'name' => $m->tenant->name,
                'status' => $m->tenant->status->value,
                'status_label' => $m->tenant->status->label(),
                'is_read_only' => ! $m->tenant->allowsWrites(),
                'display_name' => $names->resolve($m)->short(),
            ])
            ->values()
            ->all();
    }
}

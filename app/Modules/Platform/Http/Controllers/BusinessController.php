<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Platform\Http\Requests\StoreBusinessRequest;
use App\Modules\Platform\Http\Requests\UpdateBusinessStatusRequest;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ChangeTenantStatus;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Alta y listado de negocios desde la plataforma — el «cargar instancias nuevas» del SaaS.
 *
 * `tenants` es la raíz del árbol multi-tenant: no lleva `tenant_id` ni global scope, así que listarla no es una consulta
 * cross-tenant de datos de dominio, sino leer el registro de negocios. El alta reutiliza `ProvisionTenant`, que crea el
 * negocio, su dueño y su primera sucursal en una transacción y lo deja «pendiente de activación» (ya puede operar).
 */
final class BusinessController
{
    public function index(): Response
    {
        $businesses = Tenant::query()
            ->orderByDesc('id')
            ->get(['ulid', 'name', 'slug', 'status', 'contact_email', 'created_at'])
            ->map(fn (Tenant $tenant): array => [
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'status_label' => $tenant->status->label(),
                'contact_email' => $tenant->contact_email,
                'created_at' => $tenant->created_at?->toDateString(),
            ]);

        return Inertia::render('Platform/Businesses/Index', ['businesses' => $businesses]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Businesses/Create');
    }

    public function store(StoreBusinessRequest $request, ProvisionTenant $provision): RedirectResponse
    {
        $data = $request->validated();

        $result = $provision->provision(
            businessName: $data['business_name'],
            ownerEmail: $data['owner_email'],
            ownerFirstName: $data['owner_first_name'],
            ownerPaternalSurname: $data['owner_paternal_surname'],
            plainPassword: $data['plain_password'],
            ownerMaternalSurname: $data['owner_maternal_surname'] ?? null,
            branchName: ($data['branch_name'] ?? '') ?: 'Matriz',
            branchCode: ($data['branch_code'] ?? '') ?: 'MTZ',
        );

        return redirect()
            ->route('platform.businesses.index')
            ->with('success', sprintf('Negocio «%s» dado de alta.', $result['tenant']->name));
    }

    public function show(Tenant $tenant, TenantContext $context): Response
    {
        // Historial y resumen son tenant-scoped; la plataforma corre sin contexto, así que se leen DENTRO del de este
        // negocio, en una sola entrada de contexto.
        $data = $context->runFor($tenant->id, fn (): array => [
            'history' => TenantStatusTransition::query()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['from_status', 'to_status', 'reason', 'created_at']),
            'branches' => Branch::query()->count(),
            'staff' => TenantMembership::query()->where('status', MembershipStatus::Active->value)->count(),
        ]);

        $history = $data['history']->map(fn (TenantStatusTransition $t): array => [
            'from' => $t->from_status?->label(),
            'to' => $t->to_status->label(),
            'reason' => $t->reason,
            'at' => $t->created_at?->toDateTimeString(),
        ]);

        // Sólo las acciones legales DESDE el estado actual: la pantalla no ofrece un botón que el servidor rechazaría.
        $allowed = collect([TenantStatus::Active, TenantStatus::Suspended, TenantStatus::ReadOnly])
            ->filter(fn (TenantStatus $s): bool => $tenant->status->canTransitionTo($s))
            ->map(fn (TenantStatus $s): array => ['value' => $s->value, 'label' => $s->label()])
            ->values();

        return Inertia::render('Platform/Businesses/Show', [
            'business' => [
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status->value,
                'status_label' => $tenant->status->label(),
                'contact_email' => $tenant->contact_email,
                'created_at' => $tenant->created_at?->toDateString(),
            ],
            'summary' => [
                'branches' => $data['branches'],
                'staff' => $data['staff'],
            ],
            'history' => $history,
            'allowed' => $allowed,
        ]);
    }

    public function updateStatus(UpdateBusinessStatusRequest $request, Tenant $tenant, ChangeTenantStatus $service): RedirectResponse
    {
        $target = TenantStatus::from($request->validated('status'));

        try {
            $service->change($tenant, $target, $request->validated('reason'), Auth::guard('platform')->id());
        } catch (ConflictHttpException $e) {
            // Una transición ilegal desde el estado actual: se vuelve con el mensaje, no con una página de error.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('platform.businesses.show', $tenant)
            ->with('success', sprintf('«%s» ahora está %s.', $tenant->name, mb_strtolower($target->label())));
    }
}

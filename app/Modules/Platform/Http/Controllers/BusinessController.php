<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\StoreBusinessRequest;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

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
}

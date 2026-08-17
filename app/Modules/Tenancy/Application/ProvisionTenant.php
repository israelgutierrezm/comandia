<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta de tenant con su propietario y su organización mínima.
 *
 * Es el "alta asistida" del día 1 (D6) reducida a su esencia: crear el negocio, su propietario
 * y lo mínimo para que pueda operar. El autoservicio posterior y el panel de super admin
 * consumirán este mismo servicio, porque la secuencia correcta es la misma y duplicarla sería
 * la forma de que se desincronicen.
 *
 * ## Por qué crea sucursal y almacén
 *
 * "El tenant que no configura nada obtiene un restaurante funcional"
 * (ESPECIFICACIÓN_MAESTRA §1). Un tenant sin sucursal no puede hacer nada: no hay dónde
 * vender, ni de dónde descontar, ni serie de foliación. Dejarlo a que el usuario lo cree
 * convertiría el primer minuto del producto en un formulario en blanco.
 *
 * Todo en una transacción: un tenant a medio crear —con propietario y sin sucursal, o al
 * revés— es peor que ningún tenant, porque parece utilizable.
 */
final readonly class ProvisionTenant
{
    public function __construct(
        private TenantContext $context,
        private ProvisionTenantRoles $roles,
    ) {}

    /**
     * @return array{tenant: Tenant, owner: User, membership: TenantMembership, branch: Branch}
     */
    public function provision(
        string $businessName,
        string $ownerEmail,
        string $ownerFirstName,
        string $ownerPaternalSurname,
        string $plainPassword,
        ?string $ownerMaternalSurname = null,
        ?string $slug = null,
        string $timezone = 'America/Mexico_City',
        string $branchName = 'Matriz',
        string $branchCode = 'MTZ',
    ): array {
        return DB::transaction(function () use (
            $businessName, $ownerEmail, $ownerFirstName, $ownerPaternalSurname,
            $ownerMaternalSurname, $plainPassword, $slug, $timezone, $branchName, $branchCode,
        ): array {
            $tenant = Tenant::create([
                'name' => $businessName,
                'slug' => $slug ?? $this->uniqueSlug($businessName),
                // Nace pendiente de activación: el propietario puede entrar y configurar, y el
                // super admin lo activa cuando corresponda comercialmente (D70).
                'status' => TenantStatus::PendingActivation,
                'contact_email' => $ownerEmail,
            ]);

            // Desde aquí hay contexto: todo lo que sigue son modelos de dominio y sin contexto
            // lanzarían excepción, que es exactamente lo que se quiere.
            return $this->context->runFor($tenant->id, function () use (
                $tenant, $ownerEmail, $ownerFirstName, $ownerPaternalSurname,
                $ownerMaternalSurname, $plainPassword, $timezone, $branchName, $branchCode,
            ): array {
                TenantStatusTransition::create([
                    'from_status' => null,
                    'to_status' => TenantStatus::PendingActivation,
                    'reason' => 'alta del tenant',
                ]);

                $roles = $this->roles->provision();

                // El usuario global puede existir ya: una persona con dos restaurantes tiene un
                // solo correo en el SaaS (§4.1).
                $owner = User::query()->where('email', $ownerEmail)->first()
                    ?? User::create([
                        'first_name' => $ownerFirstName,
                        'paternal_surname' => $ownerPaternalSurname,
                        'maternal_surname' => $ownerMaternalSurname,
                        'email' => $ownerEmail,
                        'password' => $plainPassword,
                    ]);

                $membership = TenantMembership::create([
                    'user_id' => $owner->id,
                    'status' => MembershipStatus::Active,
                    'default_role_id' => $roles[RoleTemplates::OWNER]->id,
                    // El propietario alcanza todas las sucursales, incluidas las futuras: sin
                    // esta bandera, crear una sucursal nueva lo excluiría en silencio.
                    'has_all_branches' => true,
                    'employee_code' => 'P001',
                ]);

                $owner->assignRole($roles[RoleTemplates::OWNER]);

                $branch = Branch::create([
                    'code' => mb_strtoupper($branchCode),
                    'name' => $branchName,
                    'timezone' => $timezone,
                ]);

                // Un almacén por sucursal: la topología simple con la que D11 arranca, y desde
                // la que el tenant puede subir a consumo fino por área cuando quiera.
                $warehouse = Warehouse::create([
                    'branch_id' => $branch->id,
                    'kind' => WarehouseKind::Branch,
                    'code' => mb_strtoupper($branchCode).'-ALM',
                    'name' => 'Almacén '.$branchName,
                ]);

                $branch->update(['default_warehouse_id' => $warehouse->id]);

                return [
                    'tenant' => $tenant,
                    'owner' => $owner,
                    'membership' => $membership,
                    'branch' => $branch->refresh(),
                ];
            });
        });
    }

    /**
     * Slug único en todo el SaaS: resuelve la URL pública del menú QR y de la tienda, así que
     * dos negocios con el mismo slug harían ambigua esa URL.
     */
    private function uniqueSlug(string $businessName): string
    {
        $base = Str::slug($businessName) ?: 'negocio';
        $slug = $base;
        $suffix = 1;

        // `Tenant` no lleva global scope —es la raíz del aislamiento—, así que esta consulta es
        // legítimamente global: la unicidad del slug lo es.
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}

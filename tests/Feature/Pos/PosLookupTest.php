<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LO QUE EL POS LEE DE LA ORGANIZACIÓN, CON EL PERMISO DE LA OPERACIÓN
 *
 * La caja y el tablero de cocina pedían sus listas a endpoints de ADMINISTRACIÓN que el Cajero y la cocina
 * no tienen: la pantalla entera fallaba para justo quienes la usan. Estas lecturas sirven las mismas filas
 * bajo el permiso de operar, y sólo lo que la operación ofrece: activo y de la sucursal activa (que sale del
 * contexto, no de la petición).
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda POS', ownerEmail: 'duena@pos.mx', ownerFirstName: 'Rosa', ownerPaternalSurname: 'Lima', plainPassword: 'contrasena-larga-1',
    );
    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $otra = Branch::factory()->create();

        Terminal::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Caja 1']);
        Terminal::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Caja vieja', 'status' => 'inactive']);
        Terminal::factory()->create(['branch_id' => $otra->id, 'name' => 'Caja de otra sucursal']);

        $almacen = Warehouse::factory()->create(['branch_id' => $this->branch->id]);
        $almacenOtra = Warehouse::factory()->create(['branch_id' => $otra->id]);
        PreparationArea::factory()->create(['branch_id' => $this->branch->id, 'warehouse_id' => $almacen->id, 'name' => 'Cocina', 'uses_kds' => true]);
        PreparationArea::factory()->create(['branch_id' => $this->branch->id, 'warehouse_id' => $almacen->id, 'name' => 'Barra', 'uses_kds' => false]);
        PreparationArea::factory()->create(['branch_id' => $otra->id, 'warehouse_id' => $almacenOtra->id, 'name' => 'Cocina ajena', 'uses_kds' => true]);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** Un usuario con el rol que devuelva `$rol`, con alcance a todas las sucursales. */
function usuarioPosConRol(int $tenantId, callable $rol): User
{
    $user = User::factory()->create();

    app(TenantContext::class)->runFor($tenantId, function () use ($user, $rol): void {
        $role = $rol();
        $user->assignRole($role);
        TenantMembership::factory()->allBranches()->create(['user_id' => $user->id, 'default_role_id' => $role->id]);
    });

    return $user;
}

it('el Cajero ve las terminales activas de SU sucursal para abrir turno', function () {
    $cajero = usuarioPosConRol($this->tenant->id, fn () => Role::query()->where('name', 'Cajero')->sole());

    $resp = $this->actingAsSpa($cajero, $this->tenant->id)
        ->withHeader('X-Branch', $this->branch->ulid)
        ->getJson('/api/v1/pos/terminals')
        ->assertOk();

    // Ni la inactiva ni la de otra sucursal.
    expect(collect($resp->json('data'))->pluck('name')->all())->toBe(['Caja 1']);

    // Con su impresora (la del cajón), aunque sea nula: la caja ofrece «Abrir cajón» sin pedir el catálogo de
    // impresoras, que tampoco es suyo.
    expect($resp->json('data.0'))->toHaveKey('printer');

    // Y por esto existe: la lista de ADMINISTRACIÓN no es suya.
    $this->actingAsSpa($cajero, $this->tenant->id)
        ->withHeader('X-Branch', $this->branch->ulid)
        ->getJson('/api/v1/terminals')
        ->assertForbidden();
});

it('quien no abre turnos no lista terminales', function () {
    $mesero = usuarioPosConRol($this->tenant->id, fn () => Role::query()->where('name', 'Mesero')->sole());

    $this->actingAsSpa($mesero, $this->tenant->id)
        ->withHeader('X-Branch', $this->branch->ulid)
        ->getJson('/api/v1/pos/terminals')
        ->assertForbidden();
});

it('la cocina ve sólo las áreas con tablero de su sucursal', function () {
    $cocina = usuarioPosConRol($this->tenant->id, function (): Role {
        $rol = Role::create(['name' => 'Cocina', 'guard_name' => 'web']);
        $rol->givePermissionTo('pos.kds.view');

        return $rol;
    });

    $resp = $this->actingAsSpa($cocina, $this->tenant->id)
        ->withHeader('X-Branch', $this->branch->ulid)
        ->getJson('/api/v1/kds/areas')
        ->assertOk();

    // Ni la barra (sin tablero) ni la cocina de otra sucursal.
    expect(collect($resp->json('data'))->pluck('name')->all())->toBe(['Cocina']);

    $this->actingAsSpa($cocina, $this->tenant->id)
        ->withHeader('X-Branch', $this->branch->ulid)
        ->getJson('/api/v1/preparation-areas')
        ->assertForbidden();
});

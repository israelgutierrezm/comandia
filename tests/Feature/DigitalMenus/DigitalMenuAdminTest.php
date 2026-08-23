<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MENÚS DIGITALES — ADMINISTRACIÓN (Iteración 8, Tanda A)
 *
 * El menú por sucursal. TODO el grupo va gateado por `module:DigitalMenus`: un negocio sin el módulo recibe 404 —no ejecuta
 * su código— (§2 regla 4). Gestionar exige `digital_menus.menus.manage` y se acota a las sucursales del rol. El slug es
 * único globalmente porque la ruta pública lo usa para resolver el negocio.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];
});

afterEach(fn () => app(TenantContext::class)->forget());

function enableMenus(int $tenantId): void
{
    app(TenantContext::class)->runFor($tenantId, fn () => app(ManageTenantModules::class)->set('DigitalMenus', true));
}

it('sin el módulo activo, la administración de menús se rechaza (403)', function () {
    // El negocio no ha contratado Menús: el middleware `module:` lo corta antes de entrar al módulo (§2 regla 4).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/digital-menus')
        ->assertStatus(403);
});

it('con el módulo activo, crea, lista y actualiza el menú de una sucursal', function () {
    enableMenus($this->tenant->id);

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'fonda-centro'])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'fonda-centro')
        ->assertJsonPath('data.public_url', fn ($u) => str_ends_with((string) $u, '/m/fonda-centro'))
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/digital-menus')
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/digital-menus/{$ulid}", [
            'slug' => 'fonda-centro',
            'is_active' => true,
            'show_prices' => false,
            'theme_primary' => '#c2410c',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.show_prices', false)
        ->assertJsonPath('data.theme_primary', '#c2410c');
});

it('genera el menú en PDF', function () {
    enableMenus($this->tenant->id);

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'para-pdf'])
        ->assertStatus(201)
        ->json('data.ulid');

    $res = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->get("/api/v1/digital-menus/{$ulid}/pdf")
        ->assertOk();

    expect($res->headers->get('content-type'))->toContain('application/pdf');
    expect(substr((string) $res->getContent(), 0, 4))->toBe('%PDF');
});

it('una sucursal no puede tener dos menús', function () {
    enableMenus($this->tenant->id);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'uno'])
        ->assertStatus(201);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'dos'])
        ->assertStatus(422);
});

it('el slug es único entre TODOS los negocios', function () {
    enableMenus($this->tenant->id);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'la-fonda'])
        ->assertStatus(201);

    // Otro negocio no puede tomar el mismo slug: la ruta pública lo usa para resolver el tenant.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    enableMenus($otro['tenant']->id);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $otro['branch']->ulid, 'slug' => 'la-fonda'])
        ->assertStatus(422);
});

it('un rol sin digital_menus.menus.manage no gestiona menús', function () {
    enableMenus($this->tenant->id);

    app(TenantContext::class)->set($this->tenant->id);
    $rol = Role::create(['name' => 'Solo captura', 'guard_name' => 'web']);
    $rol->givePermissionTo('pos.orders.create');
    $user = User::factory()->create();
    $membership = TenantMembership::factory()->create(['user_id' => $user->id, 'default_role_id' => $rol->id]);
    $membership->update(['has_all_branches' => true]);
    $user->assignRole($rol);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($user, $this->tenant->id)
        ->getJson('/api/v1/digital-menus')
        ->assertStatus(403);
});

it('un negocio no ve ni actualiza el menú de otro', function () {
    enableMenus($this->tenant->id);

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/digital-menus', ['branch_ulid' => $this->branch->ulid, 'slug' => 'centro'])
        ->assertStatus(201)
        ->json('data.ulid');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    enableMenus($otro['tenant']->id);
    app(TenantContext::class)->forget();

    // El ULID ajeno no se resuelve (tenant scope): 404.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->putJson("/api/v1/digital-menus/{$ulid}", [
            'slug' => 'robado', 'is_active' => true, 'show_prices' => true, 'theme_primary' => '#000000',
        ])
        ->assertNotFound();

    // Y su listado no incluye el menú ajeno.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/digital-menus')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

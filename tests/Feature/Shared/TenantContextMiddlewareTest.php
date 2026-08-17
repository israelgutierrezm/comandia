<?php

declare(strict_types=1);

use App\Modules\Identity\Application\IssueApiToken;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Resolución del contexto de tenant por HTTP (ADR-002, §3, §8).
 *
 * Se prueba contra `/api/v1/context`, que es el endpoint real que consumen la SPA y la
 * app Flutter: verificar el middleware con una ruta de juguete probaría el juguete.
 */
beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Fonda del Centro']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Café del Norte']);

    // ---- Tenant A, completo ----
    app(TenantContext::class)->set($this->tenantA->id);

    $this->branchA = Branch::factory()->create(['code' => 'CEN', 'name' => 'Centro']);
    $this->branchA2 = Branch::factory()->create(['code' => 'SUR', 'name' => 'Sur']);
    $this->terminalA = Terminal::factory()->create(['branch_id' => $this->branchA->id]);

    $this->userA = User::factory()->create(['first_name' => 'Ana', 'paternal_surname' => 'Gómez']);

    // Sólo los tres roles que esta prueba necesita, en lugar de aprovisionar los seis
    // con el catálogo completo: esto verifica el MIDDLEWARE, no el reparto de plantillas
    // —que tiene su propio archivo—, y aprovisionar aquí costaba ~800 inserciones de
    // pivote por prueba sin verificar nada más.
    $this->gerenteA = Role::create(['name' => RoleTemplates::MANAGER, 'guard_name' => 'web']);
    $this->gerenteA->givePermissionTo('pos.orders.create', 'pos.items.cancel_commanded', 'audit.entries.view');

    $this->meseroA = Role::create(['name' => RoleTemplates::WAITER, 'guard_name' => 'web']);
    $this->meseroA->givePermissionTo('pos.orders.create');

    $this->cajeroA = Role::create(['name' => RoleTemplates::CASHIER, 'guard_name' => 'web']);

    $this->membershipA = TenantMembership::factory()->create([
        'user_id' => $this->userA->id,
        'default_role_id' => $this->gerenteA->id,
    ]);
    $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA->id]);
    $this->userA->assignRole($this->gerenteA, $this->meseroA);

    // ---- Tenant B, mínimo ----
    $this->branchB = app(TenantContext::class)->runFor(
        $this->tenantB->id,
        fn (): Branch => Branch::factory()->create(['code' => 'NTE'])
    );

    app(TenantContext::class)->forget();
});

it('resuelve el contexto desde la sesión de la SPA', function () {
    $respuesta = $this
        ->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context');

    $respuesta->assertOk()
        ->assertJsonPath('data.tenant.name', 'Fonda del Centro')
        ->assertJsonPath('data.membership.display_name', 'Ana Gómez')
        ->assertJsonPath('data.active_role.name', RoleTemplates::MANAGER)
        ->assertJsonPath('data.active_branch.code', 'CEN')
        ->assertJsonPath('data.is_read_only', false);
});

it('nunca expone identificadores internos', function () {
    // Exponer la PK secuencial filtraría volumen de negocio y permitiría enumerar
    // recursos ajenos sumando uno (§7).
    $datos = $this
        ->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->json('data');

    expect($datos['tenant'])->not->toHaveKey('id');
    expect($datos['membership'])->not->toHaveKey('id');
    expect($datos['active_role'])->not->toHaveKey('id');
    expect($datos['tenant']['ulid'])->toHaveLength(26);
});

it('resuelve el contexto desde el token de API', function () {
    // La app Flutter y los agentes de impresión: el tenant viaja con la credencial
    // (D69) y no se negocia.
    $token = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => app(IssueApiToken::class)->issue($this->membershipA, 'tableta-barra')
    );

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.tenant.name', 'Fonda del Centro');
});

it('RECHAZA con 422 si el cliente manda tenant_id', function () {
    // No se ignora en silencio: un cliente que lo envía está confundido o está probando
    // el aislamiento, y las dos cosas conviene verlas.
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context?tenant_id='.$this->tenantB->id)
        ->assertStatus(422);
});

it('RECHAZA con 422 el header X-Tenant', function () {
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Tenant', (string) $this->tenantB->id)
        ->getJson('/api/v1/context')
        ->assertStatus(422);
});

it('sin tenant en la sesión pide seleccionar negocio', function () {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/context')
        ->assertStatus(409);
});

it('un usuario sin membresía en el tenant no entra', function () {
    // Aunque conozca el identificador del tenant y esté autenticado en el SaaS.
    $intruso = User::factory()->create();

    $this->actingAsSpa($intruso, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('un tenant suspendido no permite acceso', function () {
    $this->tenantA->update(['status' => 'suspended']);

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('un tenant en sólo lectura entra y se marca como tal', function () {
    $this->tenantA->update(['status' => 'read_only']);

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.is_read_only', true);
});

it('una membresía suspendida deja de operar de inmediato', function () {
    // El caso crítico: la suspensión ocurre DESPUÉS de emitirse el token, así que el
    // middleware tiene que revalidarla en cada petición y no sólo al emitirla.
    $token = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => app(IssueApiToken::class)->issue($this->membershipA, 'tableta')
    );

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/context')
        ->assertOk();

    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->update(['status' => 'suspended'])
    );

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('acepta X-Branch dentro del alcance y lo rechaza fuera', function () {
    // El cliente elige ENTRE sus opciones; no las inventa.
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA2->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchA2->ulid)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.active_branch.code', 'SUR');
});

it('rechaza una sucursal fuera del alcance de la membresía', function () {
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchA2->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('rechaza la sucursal de otro tenant aunque se conozca su ULID', function () {
    // El identificador público no es una puerta trasera al aislamiento.
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchB->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('acepta X-Role entre los roles asignados', function () {
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Role', $this->meseroA->ulid)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.active_role.name', RoleTemplates::WAITER);
});

it('rechaza un rol que la persona no tiene asignado', function () {
    // El rol existe en el tenant y la persona no lo tiene: el cliente elige entre sus
    // roles, no entre los del negocio.
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Role', $this->cajeroA->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('los permisos que devuelve son los del rol activo, no la suma', function () {
    // La prueba de D9 en la frontera HTTP: operando como Mesero, la respuesta no debe
    // incluir los permisos de Gerente.
    $permisos = $this
        ->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Role', $this->meseroA->ulid)
        ->getJson('/api/v1/context')
        ->json('data.permissions');

    expect($permisos)->toContain('pos.orders.create');
    expect($permisos)->not->toContain('pos.items.cancel_commanded', 'audit.entries.view');
});

it('valida la terminal contra la sucursal activa', function () {
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Terminal', $this->terminalA->ulid)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.terminal.ulid', $this->terminalA->ulid);
});

it('rechaza una terminal que no pertenece a la sucursal activa', function () {
    $ajena = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn (): Terminal => Terminal::factory()->create(['branch_id' => $this->branchA2->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Terminal', $ajena->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('no expone módulos activables no contratados', function () {
    $modulos = $this
        ->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->json('data.active_modules');

    expect($modulos)->toBe([]);
});

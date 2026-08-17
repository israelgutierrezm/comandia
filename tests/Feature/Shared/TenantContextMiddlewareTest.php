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

it('RECUERDA la sucursal elegida para la siguiente petición', function () {
    // El peldaño intermedio de la cascada (header → última usada → la única del alcance) estaba
    // muerto: la columna existía, el middleware la leía y nadie la escribía nunca. Con dos
    // sucursales en el alcance, la persona aterrizaba sin sucursal activa en CADA navegación.
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA2->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchA2->ulid)
        ->getJson('/api/v1/context')
        ->assertOk();

    // Sin header en la petición siguiente: se resuelve por lo recordado.
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.active_branch.code', 'SUR');
});

it('no recuerda una sucursal que se rechazó', function () {
    // Si un intento fuera de alcance dejara rastro, un 403 podría cambiar el contexto de la
    // siguiente petición.
    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchB->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);

    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => expect($this->membershipA->fresh()->last_active_branch_id)->toBeNull()
    );
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

it('valida X-Terminal aunque NO se haya resuelto sucursal activa', function () {
    // El hueco que tenía la primera versión: cuando no había sucursal resuelta, el header se
    // ignoraba en silencio y el cliente recibía 200 creyendo operar en esa terminal. Con dos
    // sucursales en el alcance y sin X-Branch no hay sucursal activa, que es justo el escenario.
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA2->id])
    );

    $ajena = app(TenantContext::class)->runFor(
        $this->tenantB->id,
        fn (): Terminal => Terminal::factory()->create(['branch_id' => $this->branchB->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Terminal', $ajena->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('rechaza una terminal inactiva', function () {
    $inactiva = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn (): Terminal => Terminal::factory()->inactive()->create(['branch_id' => $this->branchA->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Terminal', $inactiva->ulid)
        ->getJson('/api/v1/context')
        ->assertStatus(403);
});

it('la terminal determina la sucursal activa cuando no se envía X-Branch', function () {
    // En el POS la terminal ES el contexto físico, así que exigirle al cliente los dos headers
    // sería pedirle que repita lo que ya dijo.
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA2->id])
    );

    $enSur = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn (): Terminal => Terminal::factory()->create(['branch_id' => $this->branchA2->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Terminal', $enSur->ulid)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.active_branch.code', 'SUR')
        ->assertJsonPath('data.terminal.ulid', $enSur->ulid);
});

it('rechaza terminal y sucursal contradictorias', function () {
    // Dos contextos físicos que no coinciden no se resuelven eligiendo uno.
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => $this->membershipA->branchScopes()->create(['branch_id' => $this->branchA2->id])
    );

    $enSur = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn (): Terminal => Terminal::factory()->create(['branch_id' => $this->branchA2->id])
    );

    $this->actingAsSpa($this->userA, $this->tenantA->id)
        ->withHeader('X-Branch', $this->branchA->ulid)
        ->withHeader('X-Terminal', $enSur->ulid)
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

<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;

/**
 * Administración de roles y catálogo de permisos (D10).
 *
 * El tenant combina permisos del catálogo cerrado en roles propios; no inventa permisos, no toca
 * el rol de sistema, y no puede armar roles con permisos de módulos que no contrató.
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
    $this->ownerMembership = $alta['membership'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

it('lista los roles con su conteo de personas', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(count(RoleTemplates::names()), 'data');
});

it('crea un rol combinando permisos del catálogo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/roles', [
            'name' => 'Supervisor de barra',
            'description' => 'Cobra y autoriza descuentos en la barra',
            'permissions' => ['pos.accounts.charge', 'pos.discounts.apply_account'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Supervisor de barra')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonCount(2, 'data.permissions');
});

it('RECHAZA un permiso que no está en el catálogo', function () {
    // El tenant combina permisos; no los inventa (D10). Un permiso inventado produciría un rol
    // que concede algo que ninguna verificación reconoce.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/roles', [
            'name' => 'Inventado',
            'permissions' => ['pos.hacer.lo.que.quiera'],
        ])
        ->assertStatus(422)
        // La llave del error es literalmente «permissions.0» —con punto—, así que no se puede
        // recorrer con assertJsonPath, que usa el punto como separador.
        ->assertJsonValidationErrors(['permissions.0' => 'Alguno de los permisos indicados no existe en el catálogo del sistema.']);
});

it('RECHAZA permisos de un módulo no contratado', function () {
    // Armar un rol con permisos de e-commerce sin e-commerce sería una promesa que ModuleGate
    // incumpliría en silencio al verificarla.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/roles', [
            'name' => 'Encargado de tienda',
            'permissions' => ['ecommerce.orders.accept'],
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.permissions.0', 'Estos permisos pertenecen a módulos que no tienes contratados: ecommerce.orders.accept.');
});

it('acepta esos permisos cuando el módulo SÍ está contratado', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => TenantModule::create(['module' => 'Ecommerce', 'is_enabled' => true, 'enabled_at' => now()])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/roles', [
            'name' => 'Encargado de tienda',
            'permissions' => ['ecommerce.orders.accept'],
        ])
        ->assertCreated();
});

it('el rol Propietario no se puede editar ni eliminar', function () {
    $propietario = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::OWNER)->firstOrFail()
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/roles/{$propietario->ulid}", ['name' => 'Dueño'])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/roles/{$propietario->ulid}")
        ->assertStatus(409);
});

it('nadie puede promover un rol a rol de sistema', function () {
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/roles/{$mesero->ulid}", ['is_system' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.is_system.0', 'Un rol no se puede marcar ni desmarcar como rol de sistema.');
});

it('no elimina un rol que alguien tiene asignado', function () {
    // Borrarlo dejaría a esas personas sin rol y, si era su rol por defecto, sin poder operar. El
    // descubrimiento llegaría en plena hora pico.
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $usuario = User::factory()->create();
        TenantMembership::factory()->create(['user_id' => $usuario->id]);
        $usuario->assignRole($rol);

        return $rol;
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/roles/{$mesero->ulid}")
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');
});

it('elimina un rol sin personas asignadas', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/roles', ['name' => 'Temporal', 'permissions' => []])
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/roles/{$ulid}")
        ->assertNoContent();
});

it('quitar un permiso surte efecto de inmediato, sin esperar la cache', function () {
    // `Authorize` cachea los permisos por rol: sin invalidar, un permiso recién quitado seguiría
    // concediéndose hasta que expirara la cache — y quitar un permiso suele hacerse con urgencia.
    $rol = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::create(['name' => 'Con cobro', 'guard_name' => 'web']);
        $rol->syncPermissions(['pos.accounts.charge']);

        return $rol;
    });

    app(TenantContext::class)->set($this->tenant->id);

    app(ContextHolder::class)->set(RequestContext::forMember(
        tenant: $this->tenant,
        user: $this->owner,
        membership: $this->ownerMembership,
        activeRole: $rol,
    ));

    // Calienta la cache.
    expect(app(Authorize::class)->allows('pos.accounts.charge'))->toBeTrue();

    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/roles/{$rol->ulid}", ['permissions' => []])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    app(ContextHolder::class)->set(RequestContext::forMember(
        tenant: $this->tenant,
        user: $this->owner,
        membership: $this->ownerMembership,
        activeRole: $rol->refresh(),
    ));

    expect(app(Authorize::class)->allows('pos.accounts.charge'))->toBeFalse();
});

it('el catálogo de permisos viene agrupado por módulo y con descripciones', function () {
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/permissions')
        ->assertOk()
        ->json('data');

    expect($datos)->toHaveKey('Pos');
    expect($datos['Pos'][0])->toHaveKeys(['name', 'description']);

    // Un permiso sin explicación es un permiso que alguien marcará sin entenderlo.
    expect($datos['Pos'][0]['description'])->not->toBeEmpty();
});

it('el catálogo NO ofrece módulos que el tenant no contrató', function () {
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/permissions')
        ->json('data');

    // §4.2: los permisos de módulos inactivos no se muestran al tenant.
    expect($datos)->not->toHaveKey('Ecommerce');
    expect($datos)->not->toHaveKey('DigitalMenus');

    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => TenantModule::create(['module' => 'Ecommerce', 'is_enabled' => true, 'enabled_at' => now()])
    );

    $conTienda = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/permissions')
        ->json('data');

    expect($conTienda)->toHaveKey('Ecommerce');
});

it('los roles de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    $ajeno = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/roles/{$ajeno->ulid}")
        ->assertNotFound();
});

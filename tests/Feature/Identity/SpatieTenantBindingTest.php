<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;

/**
 * Atadura de Spatie al contexto de tenant.
 *
 * Cubre un fallo que habría sido **silencioso y no determinista**: `Role` lleva
 * global scope de tenant (ADR-002), pero el registrador de Spatie construye su cache
 * con `Permission::with('roles')` y la guarda bajo **una sola llave**. Sin mover la
 * llave por tenant, la cache escrita durante la petición del tenant A se reutilizaría
 * en la del tenant B —a la que le faltarían sus propios roles—, y el síntoma serían
 * permisos denegados sin razón aparente, dependiendo de qué tenant calentó la cache
 * primero.
 */
beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->registrar = app(PermissionRegistrar::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('la llave de cache de permisos es por tenant', function () {
    $base = (string) config('permission.cache.key');

    app(TenantContext::class)->set($this->tenantA->id);
    expect($this->registrar->cacheKey)->toBe("{$base}.tenant.{$this->tenantA->id}");

    app(TenantContext::class)->set($this->tenantB->id);
    expect($this->registrar->cacheKey)->toBe("{$base}.tenant.{$this->tenantB->id}");
});

it('sin tenant vuelve a la llave base', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    app(TenantContext::class)->forget();

    expect($this->registrar->cacheKey)->toBe((string) config('permission.cache.key'));
});

it('el team de Spatie sigue al contexto sin que nadie lo sincronice a mano', function () {
    // Es lo que hace que `teams = tenant` funcione: si el team quedara desfasado del
    // contexto, Spatie resolvería roles de otro tenant.
    app(TenantContext::class)->set($this->tenantA->id);
    expect($this->registrar->getPermissionsTeamId())->toBe($this->tenantA->id);

    app(TenantContext::class)->runFor($this->tenantB->id, function () {
        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->tenantB->id);
    });

    // Y se restaura al salir, igual que el contexto.
    expect($this->registrar->getPermissionsTeamId())->toBe($this->tenantA->id);
});

it('los roles están acotados por tenant', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    $rolA = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);

    $rolB = app(TenantContext::class)->runFor(
        $this->tenantB->id,
        fn (): Role => Role::create(['name' => 'Gerente', 'guard_name' => 'web'])
    );

    // Mismo nombre en dos tenants: legítimo, y el índice único es
    // (tenant_id, name, guard_name).
    expect($rolA->id)->not->toBe($rolB->id);

    expect(Role::query()->pluck('id')->all())->toBe([$rolA->id]);
    expect(Role::query()->find($rolB->id))->toBeNull();
});

it('el rol nace con el tenant del contexto sin que nadie lo pase', function () {
    app(TenantContext::class)->set($this->tenantB->id);

    $rol = Role::create(['name' => 'Cajero', 'guard_name' => 'web']);

    expect($rol->tenant_id)->toBe($this->tenantB->id);
    expect($rol->ulid)->toHaveLength(26);
});

it('el catálogo de permisos es global y no lleva tenant', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    $permiso = Permission::create([
        'name' => 'pos.accounts.charge',
        'guard_name' => 'web',
        'module' => 'pos',
        'description' => 'Cobrar una cuenta',
    ]);

    // Visible desde cualquier tenant: es catálogo del sistema, no dato de negocio.
    app(TenantContext::class)->runFor($this->tenantB->id, function () use ($permiso) {
        expect(Permission::query()->find($permiso->id))->not->toBeNull();
    });
});

/**
 * Alcance real de este test, para no prometer más de lo que prueba: verificado
 * desactivando la atadura, las dos aserciones que la protegen de verdad son
 * "la llave de cache es por tenant" y "el team sigue al contexto". Éste pasa incluso
 * sin la atadura, porque `givePermissionTo()` invalida la cache y la relación
 * `permissions` de un rol se lee de la base, no del registrador.
 *
 * Se conserva como prueba de regresión del aislamiento de permisos por rol, no como
 * prueba de la cache. La demostración de punta a punta del envenenamiento necesita el
 * camino de verificación de permisos —el servicio `Authorize` del paso 6— y tendrá su
 * propia prueba ahí.
 */
it('un rol conserva sus propios permisos entre tenants', function () {
    Permission::create([
        'name' => 'pos.accounts.charge',
        'guard_name' => 'web',
        'module' => 'pos',
        'description' => 'Cobrar una cuenta',
    ]);

    app(TenantContext::class)->set($this->tenantA->id);
    $rolA = Role::create(['name' => 'Cajero', 'guard_name' => 'web']);
    $rolA->givePermissionTo('pos.accounts.charge');

    $rolB = app(TenantContext::class)->runFor(
        $this->tenantB->id,
        fn (): Role => Role::create(['name' => 'Cajero', 'guard_name' => 'web'])
    );

    // Calienta la cache en el contexto de A...
    expect($rolA->fresh()->permissionNames())->toBe(['pos.accounts.charge']);

    // ...y ahora B tiene que ver SU realidad, no la de A. Sin la llave por tenant,
    // aquí es donde aparecería la respuesta del tenant equivocado.
    app(TenantContext::class)->runFor($this->tenantB->id, function () use ($rolB) {
        expect($rolB->fresh()->permissionNames())->toBe([]);
    });
});

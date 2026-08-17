<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Database\Seeders\PermissionCatalogSeeder;

/**
 * Catálogo de permisos y roles plantilla (D10, D71, D72).
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('siembra el catálogo completo con módulo y descripción', function () {
    expect(Permission::query()->count())->toBe(PermissionCatalog::count());

    // La descripción es el texto que lee quien arma un rol: un permiso sin explicación
    // es un permiso que alguien marcará sin entenderlo.
    expect(Permission::query()->where('description', '')->count())->toBe(0);
    expect(Permission::query()->where('module', '')->count())->toBe(0);
});

it('el seeder es idempotente', function () {
    $antes = Permission::query()->count();

    $this->seed(PermissionCatalogSeeder::class);

    expect(Permission::query()->count())->toBe($antes);
});

it('el módulo de cada permiso coincide con su prefijo', function () {
    // De esa coincidencia depende que `ModuleGate` pueda deducir el módulo del nombre
    // sin consultar la base en cada verificación.
    foreach (Permission::query()->get() as $permiso) {
        $deducido = ModuleGate::moduleOfPermission($permiso->name);

        expect($deducido)->toBe($permiso->module, "El permiso {$permiso->name} declara módulo {$permiso->module}");
    }
});

it('crea los seis roles plantilla', function () {
    app(ProvisionTenantRoles::class)->provision();

    expect(Role::query()->pluck('name')->all())
        ->toEqualCanonicalizing(RoleTemplates::names());
});

it('el propietario tiene todos los permisos y es de sistema', function () {
    app(ProvisionTenantRoles::class)->provision();

    $propietario = Role::query()->where('name', RoleTemplates::OWNER)->first();

    expect($propietario->is_system)->toBeTrue();
    expect($propietario->permissionNames())->toEqualCanonicalizing(PermissionCatalog::names());
});

it('el gerente tiene todo salvo lo comercial y las pasarelas', function () {
    app(ProvisionTenantRoles::class)->provision();

    $gerente = Role::query()->where('name', RoleTemplates::MANAGER)->first();
    $permisos = $gerente->permissionNames();

    expect($gerente->is_system)->toBeFalse();

    // Se define por resta, así que un permiso nuevo de cualquier módulo le llega solo.
    expect($permisos)->toContain('pos.accounts.charge', 'inventory.counts.close', 'audit.entries.view');

    expect($permisos)->not->toContain(
        'tenancy.subscription.view',
        'identity.roles.delete',
        'ecommerce.gateways.configure',
    );
});

it('el mesero NO puede cobrar y el mesero con cobro SÍ', function () {
    // Cobrar es un permiso, no un puesto (D29): el mismo rol base con y sin la
    // capacidad de cerrar cuenta.
    app(ProvisionTenantRoles::class)->provision();

    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->first();
    $conCobro = Role::query()->where('name', RoleTemplates::WAITER_WITH_CHARGE)->first();

    expect($mesero->permissionNames())->not->toContain('pos.accounts.charge');
    expect($conCobro->permissionNames())->toContain('pos.accounts.charge');

    // Y lo demás es idéntico: el segundo es el primero más el cobro y la caja.
    expect(array_diff($mesero->permissionNames(), $conCobro->permissionNames()))->toBe([]);
});

it('el cajero no puede aplicar descuentos ni cancelar comandado', function () {
    // Zona de máxima auditoría (§6.3): pasa por autorización con PIN de un superior.
    app(ProvisionTenantRoles::class)->provision();

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->first();
    $permisos = $cajero->permissionNames();

    expect($permisos)->toContain('pos.accounts.charge', 'pos.cash_drawer.open');

    expect($permisos)->not->toContain(
        'pos.discounts.apply_item',
        'pos.discounts.apply_account',
        'pos.discounts.courtesy',
        'pos.items.cancel_commanded',
        'pos.sessions.withdraw',
    );
});

it('el almacenista opera inventario pero no se autoriza a sí mismo', function () {
    app(ProvisionTenantRoles::class)->provision();

    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->first();
    $permisos = $almacenista->permissionNames();

    expect($permisos)->toContain('inventory.entries.create', 'purchasing.receipts.create');

    // Quien opera el almacén no cierra sus propios conteos ni autoriza sus mermas.
    expect($permisos)->not->toContain(
        'inventory.counts.close',
        'inventory.waste.authorize_above_threshold',
        'inventory.transfers.authorize',
    );
});

it('los roles administrativos exigen 2FA', function () {
    app(ProvisionTenantRoles::class)->provision();

    expect(Role::query()->where('name', RoleTemplates::OWNER)->first()->requires_two_factor)->toBeTrue();
    expect(Role::query()->where('name', RoleTemplates::MANAGER)->first()->requires_two_factor)->toBeTrue();
    expect(Role::query()->where('name', RoleTemplates::WAITER)->first()->requires_two_factor)->toBeFalse();
});

it('reaprovisionar no duplica roles', function () {
    app(ProvisionTenantRoles::class)->provision();
    app(ProvisionTenantRoles::class)->provision();

    expect(Role::query()->count())->toBe(count(RoleTemplates::names()));
});

it('reaprovisionar NO deshace lo que el tenant configuró en un rol editable', function () {
    app(ProvisionTenantRoles::class)->provision();

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->first();
    $cajero->revokePermissionTo('pos.cash_drawer.open');

    app(ProvisionTenantRoles::class)->provision();

    // Reponer el permiso sería deshacer en silencio una decisión deliberada del tenant.
    expect($cajero->fresh()->permissionNames())->not->toContain('pos.cash_drawer.open');
});

it('reaprovisionar SÍ resincroniza al propietario', function () {
    app(ProvisionTenantRoles::class)->provision();

    $propietario = Role::query()->where('name', RoleTemplates::OWNER)->first();
    $propietario->revokePermissionTo('audit.entries.view');

    app(ProvisionTenantRoles::class)->provision();

    // Es rol de sistema: "todos los permisos" es su definición, no su configuración.
    expect($propietario->fresh()->permissionNames())->toContain('audit.entries.view');
});

it('falla ruidosamente si el catálogo de permisos no está sembrado', function () {
    // Crear roles a medias sería peor que fallar: un rol al que le faltan permisos es un
    // rol que no puede operar, y el síntoma aparecería días después como "no tengo
    // permiso" sin causa aparente.
    DB::table('role_has_permissions')->delete();
    Permission::query()->delete();

    expect(fn () => app(ProvisionTenantRoles::class)->provision())
        ->toThrow(RuntimeException::class, 'no está sembrado por completo');
});

it('cada tenant tiene sus propios roles plantilla', function () {
    app(ProvisionTenantRoles::class)->provision();

    $otro = Tenant::factory()->create();

    app(TenantContext::class)->runFor($otro->id, function () {
        app(ProvisionTenantRoles::class)->provision();

        expect(Role::query()->count())->toBe(count(RoleTemplates::names()));
    });

    // Seis en cada tenant, no doce en uno.
    expect(Role::query()->count())->toBe(count(RoleTemplates::names()));
});

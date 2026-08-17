<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Authorization\AuthorizationDenied;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;

/**
 * Autorización por ROL ACTIVO (D9) — el comportamiento que define el modelo de
 * permisos del proyecto.
 *
 * La prueba central es "un rol activo sin el permiso recibe negativa aunque otro rol
 * del usuario sí lo tenga": es la que distingue este diseño de usar Spatie tal cual, y
 * la que hace que un mesero que además es gerente no pueda cancelar un platillo
 * comandado mientras opera como mesero.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();

    // Los permisos vienen del catálogo real, sembrado una vez por corrida. Crearlos a
    // mano en la prueba probaría contra un catálogo inventado, y lo que se quiere
    // verificar es el comportamiento con los permisos que el sistema tiene de verdad.

    $this->gerente = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);
    $this->gerente->givePermissionTo('pos.items.cancel_commanded', 'pos.orders.create');

    $this->mesero = Role::create(['name' => 'Mesero', 'guard_name' => 'web']);
    $this->mesero->givePermissionTo('pos.orders.create');

    $this->user = User::factory()->create();

    $this->membership = TenantMembership::factory()->create([
        'user_id' => $this->user->id,
        'has_all_branches' => false,
    ]);
    // Sin `tenant_id`: lo rellena BelongsToTenant desde el contexto, y pasarlo sería
    // asignación masiva de una columna protegida.
    $this->membership->branchScopes()->create(['branch_id' => $this->branch->id]);

    // La persona tiene AMBOS roles en el tenant.
    $this->user->assignRole($this->gerente, $this->mesero);

    $this->authorize = app(Authorize::class);

    $this->actuarComo = function (Role $rolActivo, ?Branch $sucursal = null): void {
        app(ContextHolder::class)->set(RequestContext::forMember(
            tenant: $this->tenant,
            user: $this->user,
            membership: $this->membership,
            activeRole: $rolActivo,
            activeBranch: $sucursal ?? $this->branch,
        ));
    };
});

afterEach(function () {
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

it('el rol activo con el permiso autoriza', function () {
    ($this->actuarComo)($this->gerente);

    expect($this->authorize->can('pos.items.cancel_commanded'))->toBeTrue();
});

it('ES EL TEST DE D9: el rol activo sin el permiso NIEGA, aunque otro rol lo tenga', function () {
    // El usuario tiene el rol Gerente, que sí puede cancelar comandado. Pero está
    // operando como Mesero.
    ($this->actuarComo)($this->mesero);

    // ---------------------------------------------------------------------
    // Esta primera aserción existe para que el test demuestre POR QUÉ hace falta
    // todo el servicio de autorización, en lugar de sólo afirmar que funciona.
    //
    // Spatie, preguntado directamente, dice que SÍ puede: suma los permisos de
    // todos los roles del usuario en el tenant y el rol Gerente los tiene. Si el
    // proyecto usara `$user->can()`, un mesero operando como mesero podría
    // cancelar un platillo ya comandado — y nadie lo notaría hasta auditar.
    //
    // Es el único lugar del proyecto donde se llama a esta API a propósito, y está
    // en `tests/`, que el candado estructural no vigila.
    // ---------------------------------------------------------------------
    expect($this->user->hasPermissionTo('pos.items.cancel_commanded'))->toBeTrue();

    // Y aquí lo que el proyecto responde: NO, porque el rol activo no lo tiene.
    expect($this->authorize->can('pos.items.cancel_commanded'))->toBeFalse();

    // Lo que el rol activo sí permite, sigue permitido.
    expect($this->authorize->can('pos.orders.create'))->toBeTrue();
});

it('authorize() lanza 403 sin revelar qué permiso faltaba', function () {
    ($this->actuarComo)($this->mesero);

    try {
        $this->authorize->authorize('pos.items.cancel_commanded');
        $this->fail('Debió lanzar AuthorizationDenied.');
    } catch (AuthorizationDenied $e) {
        expect($e->getStatusCode())->toBe(403);
        // Decirle al cliente el nombre exacto del permiso que le falta le enumera el
        // catálogo de capacidades del sistema.
        expect($e->getMessage())->not->toContain('pos.items.cancel_commanded');
    }
});

it('sin contexto resuelto no autoriza nada', function () {
    app(ContextHolder::class)->forget();

    expect($this->authorize->can('pos.orders.create'))->toBeFalse();
});

it('niega si la sucursal está fuera del alcance de la membresía', function () {
    ($this->actuarComo)($this->gerente);

    expect($this->authorize->can('pos.orders.create', $this->branch->id))->toBeTrue();
    expect($this->authorize->can('pos.orders.create', $this->otherBranch->id))->toBeFalse();
});

it('has_all_branches alcanza también las sucursales creadas después', function () {
    // Sin esta bandera, dar de alta una sucursal excluiría en silencio al propietario y
    // nadie se daría cuenta hasta que no apareciera en el selector.
    $this->membership->update(['has_all_branches' => true]);
    $this->membership->refresh();

    ($this->actuarComo)($this->gerente);

    $nueva = Branch::factory()->create();

    expect($this->authorize->can('pos.orders.create', $nueva->id))->toBeTrue();
});

it('niega un permiso de módulo no contratado, aunque el rol lo tenga', function () {
    // "Un tenant sin e-commerce no ejecuta una sola línea de ese módulo" (§2 regla 4).
    // El paso del módulo va ANTES del paso del rol, y es el que corta más casos.
    $this->gerente->givePermissionTo('ecommerce.orders.accept');

    ($this->actuarComo)($this->gerente);

    expect($this->authorize->can('ecommerce.orders.accept'))->toBeFalse();

    TenantModule::create(['module' => 'Ecommerce', 'is_enabled' => true, 'enabled_at' => now()]);
    app(ModuleGate::class)->forgetTenant($this->tenant->id);
    $this->authorize->forgetRole($this->gerente);

    expect($this->authorize->can('ecommerce.orders.accept'))->toBeTrue();
});

it('un tenant en sólo lectura autoriza lecturas y bloquea escrituras', function () {
    // Impago con periodo de gracia: los datos son del tenant y tiene que poder
    // consultarlos y exportarlos; lo que no puede es cobrar.
    $tenantSoloLectura = Tenant::factory()->readOnly()->create();

    app(ContextHolder::class)->set(RequestContext::forMember(
        tenant: $tenantSoloLectura,
        user: $this->user,
        membership: $this->membership,
        activeRole: $this->gerente,
        activeBranch: $this->branch,
    ));

    expect($this->authorize->can('pos.orders.create'))->toBeTrue();

    expect(fn () => $this->authorize->authorizeWrite('pos.orders.create'))
        ->toThrow(AuthorizationDenied::class);
});

it('los permisos del rol activo alimentan la navegación del frontend', function () {
    ($this->actuarComo)($this->mesero);

    // Sólo los del rol activo, y filtrados por módulo activo: la navegación no debe
    // ofrecer un botón que al pulsarlo dé 403.
    expect($this->authorize->permissionsOfActiveRole())->toBe(['pos.orders.create']);
});

it('el contexto es inmutable: cambiar de rol produce otro contexto', function () {
    ($this->actuarComo)($this->mesero);

    $contexto = app(ContextHolder::class)->get();
    $conGerente = $contexto->withRole($this->gerente);

    expect($contexto->activeRole->id)->toBe($this->mesero->id);
    expect($conGerente->activeRole->id)->toBe($this->gerente->id);
    expect($conGerente)->not->toBe($contexto);
});

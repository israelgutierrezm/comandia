<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MÓDULOS ACTIVABLES DEL TENANT (Iteración 8, Tanda A, D3)
 *
 * El propietario enciende/apaga los módulos activables (Tienda, Menús). Es la primera superficie del módulo `Tenancy`.
 * Activar es una decisión comercial (D4): la ve y la cambia el propietario, no el gerente. Un cambio surte efecto de
 * inmediato (la cache de módulos se invalida al guardar).
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
});

afterEach(fn () => app(TenantContext::class)->forget());

it('lista los módulos activables, inactivos por omisión', function () {
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/modules')
        ->assertOk()
        ->json('data');

    $modules = collect($data)->pluck('enabled', 'module');

    // El registro declarativo tiene DigitalMenus y Ecommerce como activables; ambos nacen apagados.
    expect($modules)->toHaveKeys(['DigitalMenus', 'Ecommerce']);
    expect($modules->get('Ecommerce'))->toBeFalse();
    expect($modules->get('DigitalMenus'))->toBeFalse();
});

it('el propietario activa y desactiva un módulo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/modules/Ecommerce', ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data', fn ($data) => collect($data)->firstWhere('module', 'Ecommerce')['enabled'] === true);

    app(TenantContext::class)->set($this->tenant->id);
    expect(app(ManageTenantModules::class)->state()['Ecommerce'])->toBeTrue();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/modules/Ecommerce', ['enabled' => false])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(app(ManageTenantModules::class)->state()['Ecommerce'])->toBeFalse();
});

it('encender y apagar un módulo queda en la bitácora; repetir el mismo estado no', function () {
    // Apagar la tienda saca de línea la tienda pública y la entrada de los marketplaces: la bitácora tenía la acción en
    // su catálogo y nadie la registraba.
    $poner = fn (bool $activo) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/modules/Ecommerce', ['enabled' => $activo])
        ->assertOk();

    $poner(true);
    $poner(true); // lo mismo otra vez: no es un hecho
    $poner(false);

    app(TenantContext::class)->set($this->tenant->id);

    expect(AuditEntry::query()
        ->whereIn('action', [AuditAction::TENANT_MODULE_ENABLED, AuditAction::TENANT_MODULE_DISABLED])
        ->orderBy('id')
        ->pluck('action')
        ->all())->toBe([AuditAction::TENANT_MODULE_ENABLED, AuditAction::TENANT_MODULE_DISABLED]);
});

it('rechaza un módulo que no es activable', function () {
    // El POS es núcleo, no activable: no se «enciende».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/modules/Pos', ['enabled' => true])
        ->assertStatus(422);
});

it('un rol sin el permiso de administrar módulos no puede cambiarlos', function () {
    // Rol que VE pero no ADMINISTRA módulos.
    app(TenantContext::class)->set($this->tenant->id);
    $rol = Role::create(['name' => 'Solo lectura de módulos', 'guard_name' => 'web']);
    $rol->givePermissionTo('tenancy.modules.view');

    $user = User::factory()->create();
    $membership = TenantMembership::factory()->create(['user_id' => $user->id, 'default_role_id' => $rol->id]);
    $membership->update(['has_all_branches' => true]);
    $user->assignRole($rol);
    app(TenantContext::class)->forget();

    // Ve la lista…
    $this->actingAsSpa($user, $this->tenant->id)
        ->getJson('/api/v1/modules')
        ->assertOk();

    // …pero no la cambia.
    $this->actingAsSpa($user, $this->tenant->id)
        ->putJson('/api/v1/modules/Ecommerce', ['enabled' => true])
        ->assertStatus(403);
});

it('activar un módulo en un negocio no lo activa en otro', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/modules/Ecommerce', ['enabled' => true])
        ->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/modules')
        ->assertOk()
        ->json('data');

    expect(collect($data)->firstWhere('module', 'Ecommerce')['enabled'])->toBeFalse();
});

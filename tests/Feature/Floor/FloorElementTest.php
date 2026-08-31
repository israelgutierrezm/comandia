<?php

declare(strict_types=1);

use App\Modules\Floor\Infrastructure\Models\FloorElement;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * ELEMENTOS DECORATIVOS DEL SALÓN (ADR-011): muros, puertas y rótulos.
 *
 * No son mesas —no tienen código, capacidad, estado ni cuenta— y viven en su propia tabla. Se editan en el editor y se
 * dibujan detrás de las mesas; se borran de verdad.
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

    app(TenantContext::class)->forget();

    $this->planPorOmision = function (): FloorPlan {
        return app(TenantContext::class)->runFor($this->tenant->id, fn (): FloorPlan => FloorPlan::query()->firstOr(
            fn () => FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Planta baja', 'is_default' => true]),
        ));
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('un muro se crea centrado en el lienzo, con su tamaño por omisión', function () {
    $plan = ($this->planPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'wall'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'wall')
        ->assertJsonPath('data.geometry.width', '200.00')
        // Centrado: (1200 − 200) / 2 = 500.
        ->assertJsonPath('data.geometry.x', '500.00');
});

it('un rótulo lleva texto', function () {
    $plan = ($this->planPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'label', 'text' => 'Cocina'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'label')
        ->assertJsonPath('data.text', 'Cocina');
});

it('el tipo no es libre: rechaza uno inventado', function () {
    $plan = ($this->planPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'fuente'])
        ->assertStatus(422);
});

it('el tipo no se cambia, pero el texto y la geometría sí', function () {
    $plan = ($this->planPorOmision)();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'label', 'text' => 'Barra'])
        ->json('data.ulid');

    // El tipo se fija al crear: cambiarlo es borrarlo y crear otro.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/floor-elements/{$ulid}", ['kind' => 'wall'])
        ->assertStatus(422);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/floor-elements/{$ulid}", ['text' => 'Barra fría', 'x' => 100, 'rotation' => 90])
        ->assertOk()
        ->assertJsonPath('data.text', 'Barra fría')
        ->assertJsonPath('data.geometry.x', '100.00')
        ->assertJsonPath('data.geometry.rotation', '90.00');
});

it('un elemento se borra de verdad', function () {
    $plan = ($this->planPorOmision)();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'door'])
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/floor-elements/{$ulid}")
        ->assertNoContent();

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn (): int => FloorElement::query()->count()))->toBe(0);
});

it('el plano completo trae sus elementos junto a las mesas', function () {
    $plan = ($this->planPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'wall'])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/floor-plans/{$plan->ulid}")
        ->assertOk()
        ->assertJsonCount(1, 'data.elements')
        ->assertJsonPath('data.elements.0.kind', 'wall');
});

it('el guardado del layout mueve la geometría de los elementos', function () {
    $plan = ($this->planPorOmision)();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'wall'])
        ->json('data.ulid');

    $version = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/floor-plans/{$plan->ulid}")->json('data.version');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$plan->ulid}/layout", [
            'version' => $version,
            'tables' => [],
            'elements' => [
                ['ulid' => $ulid, 'x' => 10, 'y' => 20, 'width' => 300, 'height' => 15, 'rotation' => 0],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.elements.0.geometry.x', '10.00')
        ->assertJsonPath('data.elements.0.geometry.width', '300.00');
});

it('el guardado rechaza un elemento de otro plano', function () {
    $planA = ($this->planPorOmision)();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$planA->ulid}/elements", ['kind' => 'wall'])
        ->json('data.ulid');

    // Un segundo plano de la MISMA sucursal.
    $planB = app(TenantContext::class)->runFor($this->tenant->id, fn (): FloorPlan => FloorPlan::create([
        'branch_id' => $this->branch->id, 'name' => 'Terraza', 'is_default' => false,
    ]));

    $version = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/floor-plans/{$planB->ulid}")->json('data.version');

    // Colar el elemento del plano A en el guardado del plano B lo movería a un salón que no es el suyo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$planB->ulid}/layout", [
            'version' => $version,
            'tables' => [],
            'elements' => [['ulid' => $ulid, 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 15, 'rotation' => 0]],
        ])
        ->assertStatus(409);
});

it('los elementos de un negocio no se ven desde otro', function () {
    $plan = ($this->planPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'wall'])
        ->assertCreated();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $enA = app(TenantContext::class)->runFor($this->tenant->id, fn (): int => FloorElement::query()->count());
    $enB = app(TenantContext::class)->runFor($otro['tenant']->id, fn (): int => FloorElement::query()->count());

    expect($enA)->toBe(1);
    expect($enB)->toBe(0);
    expect(FloorElement::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('el mesero no configura elementos del salón', function () {
    $plan = ($this->planPorOmision)();

    app(TenantContext::class)->set($this->tenant->id);
    $mesero = User::factory()->create();
    $membresia = TenantMembership::factory()->create([
        'user_id' => $mesero->id,
        'employee_code' => 'W001',
        'has_all_branches' => true,
    ]);
    $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $mesero->syncRoles([$rol]);
    $membresia->update(['default_role_id' => $rol->id]);
    app(TenantContext::class)->forget();

    // Configurar el salón —incluidos sus muros— es del gerente, no del mesero.
    $this->actingAsSpa($mesero, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan->ulid}/elements", ['kind' => 'wall'])
        ->assertForbidden();
});

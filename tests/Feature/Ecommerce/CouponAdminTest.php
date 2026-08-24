<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CUPONES — ADMINISTRACIÓN (Iteración 8, Tanda D, D3 parte 1)
 *
 * Crear, listar, editar y quitar cupones. Gateado por `module:Ecommerce` y `ecommerce.coupons.manage`. El valor se valida
 * según el tipo (porcentaje 1–100, monto fijo positivo, envío gratis sin valor) y el código es único por negocio.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);
        $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('crea cupones de cada tipo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'PORCIEN', 'type' => 'percentage', 'value' => '10', 'is_active' => true])
        ->assertStatus(201)->assertJsonPath('data.type', 'percentage')->assertJsonPath('data.value', '10.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'CINCUENTA', 'type' => 'fixed', 'value' => '50', 'is_active' => true])
        ->assertStatus(201)->assertJsonPath('data.type', 'fixed')->assertJsonPath('data.value', '50.00');

    // El envío gratis no lleva valor: se normaliza a cero.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'ENVIOGRATIS', 'type' => 'free_shipping', 'is_active' => true])
        ->assertStatus(201)->assertJsonPath('data.type', 'free_shipping')->assertJsonPath('data.value', '0.00');
});

it('rechaza valores fuera de rango según el tipo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'MAL', 'type' => 'percentage', 'value' => '150', 'is_active' => true])
        ->assertStatus(422); // un porcentaje > 100

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'MALDOS', 'type' => 'fixed', 'value' => '0', 'is_active' => true])
        ->assertStatus(422); // un monto fijo no positivo
});

it('el código es único por negocio, pero dos negocios pueden compartirlo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'BIENVENIDO', 'type' => 'percentage', 'value' => '10', 'is_active' => true])->assertStatus(201);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'BIENVENIDO', 'type' => 'fixed', 'value' => '5', 'is_active' => true])->assertStatus(422);

    // Otro negocio puede usar el mismo código: `/t/{slug}` ya resuelve el tenant.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->runFor($otro['tenant']->id, function () use ($otro): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);
        Store::create(['slug' => 'cafe-tienda', 'name' => 'Café', 'is_active' => true])
            ->storeBranches()->create(['branch_id' => $otro['branch']->id]);
    });
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->postJson('/api/v1/coupons', ['code' => 'BIENVENIDO', 'type' => 'percentage', 'value' => '15', 'is_active' => true])->assertStatus(201);
});

it('lista, edita y quita cupones', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/coupons', ['code' => 'X', 'type' => 'percentage', 'value' => '10', 'is_active' => true])
        ->assertStatus(201)->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/coupons')->assertOk()->assertJsonCount(1, 'data');

    // Editar (el código no choca consigo mismo).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/coupons/{$ulid}", ['code' => 'X', 'type' => 'percentage', 'value' => '20', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.value', '20.00')->assertJsonPath('data.is_active', false);

    $this->actingAsSpa($this->owner, $this->tenant->id)->deleteJson("/api/v1/coupons/{$ulid}")->assertNoContent();
    $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/coupons')->assertOk()->assertJsonCount(0, 'data');
});

it('sin el módulo, gestionar cupones se rechaza (403)', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('Ecommerce', false));

    $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/coupons')->assertStatus(403);
});

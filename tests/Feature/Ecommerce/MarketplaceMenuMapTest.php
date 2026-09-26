<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MAPEO DEL MENÚ DE MARKETPLACE (ADR-015, Fase 2)
 *
 * Qué artículo de Comandia es cada ítem del menú de DiDi/Uber/Rappi. Sin mapeo la ingesta rechaza el
 * pedido, así que esta API es lo que vuelve un canal usable de punta a punta. Upsert por (canal, id
 * externo); sólo artículos vendibles; aislado por negocio; gestionarlo exige configurar la tienda.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda mapeada', ownerEmail: 'duena@mapeo.mx', ownerFirstName: 'Rosa', ownerPaternalSurname: 'Lima', plainPassword: 'contrasena-larga-1',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        $pza = Unit::query()->where('code', 'pza')->sole()->id;
        $category = ArticleCategory::create(['name' => 'Fuertes', 'level' => 1]);

        $this->sellable = Article::create([
            'name' => 'Enchiladas', 'category_id' => $category->id, 'base_unit_id' => $pza,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true,
        ]);
        $this->otherSellable = Article::create([
            'name' => 'Chilaquiles', 'category_id' => $category->id, 'base_unit_id' => $pza,
            'is_sellable' => true, 'base_price' => '90.00', 'is_available_in_pos' => true,
        ]);
        $this->supply = Article::create([
            'name' => 'Tortilla', 'base_unit_id' => $pza, 'is_supply' => true, 'is_inventoriable' => true,
        ]);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('mapea un ítem y lo lista sólo en su canal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'didi_food', 'external_item_id' => 'ITEM-1', 'article_ulid' => $this->sellable->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.external_item_id', 'ITEM-1')
        ->assertJsonPath('data.article.name', 'Enchiladas');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/marketplace-menu-maps?channel=didi_food')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Otro canal no lo ve.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/marketplace-menu-maps?channel=rappi')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('volver a mapear el mismo ítem cambia su artículo, no duplica la fila', function () {
    $body = ['channel' => 'uber_eats', 'external_item_id' => 'ITEM-2', 'article_ulid' => $this->sellable->ulid];
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson('/api/v1/marketplace-menu-maps', $body)->assertCreated();

    $body['article_ulid'] = $this->otherSellable->ulid;
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', $body)
        ->assertOk() // actualizó, no creó
        ->assertJsonPath('data.article.name', 'Chilaquiles');

    app(TenantContext::class)->set($this->tenant->id);
    expect(MarketplaceMenuMap::query()->where('channel', 'uber_eats')->where('external_item_id', 'ITEM-2')->count())->toBe(1);
});

it('rechaza mapear un artículo que no es vendible', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'didi_food', 'external_item_id' => 'ITEM-3', 'article_ulid' => $this->supply->ulid,
        ])
        ->assertStatus(422);
});

it('quita un mapeo', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'rappi', 'external_item_id' => 'ITEM-4', 'article_ulid' => $this->sellable->ulid,
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/marketplace-menu-maps/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/marketplace-menu-maps?channel=rappi')
        ->assertJsonCount(0, 'data');
});

it('no alcanza artículos ni mapeos de otro negocio', function () {
    $altaB = app(ProvisionTenant::class)->provision(
        businessName: 'Otra fonda', ownerEmail: 'duenob@otra.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Ruiz', plainPassword: 'contrasena-larga-2',
    );

    [$articuloB, $mapeoB] = app(TenantContext::class)->runFor($altaB['tenant']->id, function (): array {
        // Un vendible necesita categoría (invariante del catálogo).
        $articulo = Article::create([
            'name' => 'Tacos B', 'category_id' => ArticleCategory::create(['name' => 'Tacos', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '50.00', 'is_available_in_pos' => true,
        ]);
        $mapeo = MarketplaceMenuMap::create(['channel' => 'didi_food', 'external_item_id' => 'B-1', 'article_id' => $articulo->id]);

        return [$articulo, $mapeo];
    });

    // El artículo de B no existe para A (404), ni su mapeo se puede quitar desde A.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'didi_food', 'external_item_id' => 'X', 'article_ulid' => $articuloB->ulid,
        ])
        ->assertNotFound();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/marketplace-menu-maps/{$mapeoB->ulid}")
        ->assertNotFound();
});

it('gestionar el mapeo exige el permiso de configurar la tienda', function () {
    $empleado = User::factory()->create();
    app(TenantContext::class)->runFor($this->tenant->id, function () use ($empleado): void {
        $rol = Role::create(['name' => 'Sólo pedidos', 'guard_name' => 'web']);
        $rol->givePermissionTo('ecommerce.orders.view');
        $empleado->assignRole($rol);
        TenantMembership::factory()->allBranches()->create(['user_id' => $empleado->id, 'default_role_id' => $rol->id]);
    });

    $this->actingAsSpa($empleado, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'didi_food', 'external_item_id' => 'ITEM-5', 'article_ulid' => $this->sellable->ulid,
        ])
        ->assertForbidden();
});

it('un mapeo hecho por la API deja ingerir el pedido de punta a punta', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        Warehouse::factory()->create(['branch_id' => $this->branch->id]);
        $store = Store::create(['slug' => 'fonda-mapeada', 'name' => 'Fonda', 'is_active' => true]);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);
        DeliveryChannelSetting::create(['branch_id' => $this->branch->id, 'channel' => 'fake', 'is_active' => true]);
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/marketplace-menu-maps', [
            'channel' => 'fake', 'external_item_id' => 'EXT-9', 'article_ulid' => $this->sellable->ulid,
        ])
        ->assertCreated();

    $this->postJson('/t/fonda-mapeada/webhook/marketplace/fake', [
        'external_order_id' => 'PED-77',
        'customer_name' => 'Cliente de prueba',
        'items' => [['external_item_id' => 'EXT-9', 'quantity' => 1]],
    ])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('external_order_id', 'PED-77')->exists())->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TIENDA EN MODO ENVÍO (retail, ADR-013)
 *
 * Una tienda `dispatch` sirve a un giro que empaca y envía después (p. ej. una ferretería): aceptar un
 * pedido NO genera comanda de cocina (aunque existan reglas de ruteo), pero SÍ descuenta inventario; y el
 * fulfillment avanza empacado → enviado → entregado, con paquetería y guía. Además, cada tienda declara
 * qué entregas ofrece, y un checkout con una entrega no ofrecida se rechaza.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Ferretería', ownerEmail: 'dueno@ferre.mx', ownerFirstName: 'Raúl', ownerPaternalSurname: 'Díaz', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        Warehouse::factory()->create(['branch_id' => $this->branch->id]); // almacén de la sucursal

        // Un área con regla de ruteo: EXISTE a propósito, para probar que el modo envío la ignora.
        $areaWarehouse = Warehouse::factory()->create(['branch_id' => $this->branch->id, 'kind' => WarehouseKind::Branch]);
        $this->area = PreparationArea::create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $areaWarehouse->id, 'code' => 'ALM', 'name' => 'Almacén',
        ]);
        $category = ArticleCategory::create(['name' => 'Herramientas', 'level' => 1]);
        PosAreaRoute::create([
            'branch_id' => $this->branch->id, 'article_category_id' => $category->id, 'preparation_area_id' => $this->area->id,
        ]);

        // Artículo de ferretería: vendible + inventariable, NO producible (no tiene receta).
        $this->article = Article::create([
            'name' => 'Martillo', 'category_id' => $category->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '150.00', 'is_available_in_pos' => false, 'is_inventoriable' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        // La tienda en MODO ENVÍO, que ofrece pickup y envío.
        $this->store = Store::create([
            'slug' => 'ferre-tienda', 'name' => 'Ferretería', 'is_active' => true,
            'fulfillment_mode' => 'dispatch', 'offers_pickup' => true, 'offers_shipping' => true,
        ]);
        $this->store->storeBranches()->create(['branch_id' => $this->branch->id]);
        $this->zone = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Nacional', 'cost' => '99.00', 'is_active' => true]);

        PaymentGatewaySetting::create(['active_gateway' => 'fake']);
        $this->customer = Customer::create(['name' => 'Sara', 'phone' => '5511110000', 'email' => 'sara@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** Coloca y paga un pedido de envío; devuelve el ULID. Repone el guard `web` para el personal. */
function placePaidShippingOrder(): string
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/ferre-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $ulid = test()->postJson('/t/ferre-tienda/checkout', [
        'delivery_type' => 'shipping', 'zone_ulid' => test()->zone->ulid, 'address' => 'Calle Falsa 123',
    ])->assertStatus(201)->json('data.ulid');
    test()->postJson('/t/ferre-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '249.00'])->assertOk();
    auth()->shouldUse('web');

    return $ulid;
}

it('en modo envío el pedido NO se rutea a área aunque exista la regla', function () {
    $ulid = placePaidShippingOrder();

    app(TenantContext::class)->set($this->tenant->id);
    $item = Order::query()->where('ulid', $ulid)->sole()->items()->sole();

    // La regla de ruteo existe (categoría → área), pero el modo envío no la aplica: sin área = sin comanda.
    expect($item->preparation_area_id)->toBeNull();
});

it('aceptar en modo envío descuenta inventario (sin comanda de cocina)', function () {
    $ulid = placePaidShippingOrder();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    app(TenantContext::class)->set($this->tenant->id);
    // El inventario SÍ se movió (la venta descuenta igual); lo que no hay es comanda (área nula).
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeTrue();
});

it('el fulfillment de envío avanza empacado → enviado → entregado con guía', function () {
    $ulid = placePaidShippingOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/pack")->assertOk()->assertJsonPath('data.status', 'packed');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/ship", ['carrier' => 'Estafeta', 'tracking_number' => 'GUIA-123'])
        ->assertOk()
        ->assertJsonPath('data.status', 'shipped')
        ->assertJsonPath('data.carrier', 'Estafeta')
        ->assertJsonPath('data.tracking_number', 'GUIA-123');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('ulid', $ulid)->sole()->shipped_at)->not->toBeNull();
});

it('no se puede saltar de aceptado a enviado sin empacar', function () {
    $ulid = placePaidShippingOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/ship")->assertStatus(422);
});

it('una tienda que no ofrece pickup rechaza un checkout pickup', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['offers_pickup' => false]));

    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/ferre-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $this->postJson('/t/ferre-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(422);
});

it('una tienda que no ofrece envío rechaza un checkout de envío', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['offers_shipping' => false]));

    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/ferre-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $this->postJson('/t/ferre-tienda/checkout', [
        'delivery_type' => 'shipping', 'zone_ulid' => $this->zone->ulid, 'address' => 'Calle Falsa 123',
    ])->assertStatus(422);
});

it('configurar la tienda exige ofrecer al menos una entrega', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/store', [
            'slug' => 'ferre-tienda', 'name' => 'Ferretería', 'is_active' => true, 'theme_primary' => '#0b8a99',
            'fulfillment_mode' => 'dispatch', 'offers_pickup' => false, 'offers_shipping' => false,
            'branch_ulids' => [$this->branch->ulid],
        ])
        ->assertStatus(422);
});

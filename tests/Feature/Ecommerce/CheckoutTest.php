<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CHECKOUT + PEDIDOS (Iteración 8, Tanda C parte 2)
 *
 * Comprar exige cliente registrado (D333). El carrito se materializa en un pedido foliado, con importes congelados; nace
 * `pending_payment`. Entrega pickup o envío por zona (el costo suma al total). Los pedidos son sólo del cliente.
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

        $this->article = Article::create([
            'name' => 'Cerveza', 'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);
        $this->zone = ShippingZone::create(['store_id' => $store->id, 'name' => 'Centro', 'cost' => '50.00', 'is_active' => true]);

        // El checkout inicia el cobro con la pasarela activa: sin una configurada, responde 422 antes de crear el pedido.
        PaymentGatewaySetting::create(['active_gateway' => 'fake']);

        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el checkout exige cliente autenticado', function () {
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(401);
});

it('materializa el carrito en un pedido con pickup', function () {
    $this->actingAs($this->customer, 'customer');

    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 2])->assertStatus(201);

    $order = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])
        ->assertStatus(201)
        ->json('data');

    expect($order['status'])->toBe('pending_payment');
    expect($order['total'])->toBe('200.00'); // 2 x 100, sin envío
    expect($order['folio'])->toBe('WEB-000001');
    expect($order['items'])->toHaveCount(1);
    expect($order['items'][0]['quantity'])->toBe(2);

    // El carrito quedó vacío.
    $this->getJson('/t/fonda-tienda/cart')->assertOk()->assertJsonPath('data.items', []);
});

it('suma el costo de la zona en un pedido con envío', function () {
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);

    $order = $this->postJson('/t/fonda-tienda/checkout', [
        'delivery_type' => 'shipping', 'zone_ulid' => $this->zone->ulid, 'address' => 'Calle Falsa 123',
    ])->assertStatus(201)->json('data');

    expect($order['shipping_cost'])->toBe('50.00');
    expect($order['total'])->toBe('150.00'); // 100 + 50
    expect($order['delivery_address'])->toBe('Calle Falsa 123');
});

it('el envío exige zona y dirección', function () {
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);

    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'shipping'])->assertStatus(422);
});

it('un carrito vacío no se puede pagar', function () {
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(422);
});

it('sin pasarela configurada, el checkout falla temprano sin dejar pedido huérfano', function () {
    // El cobro se valida ANTES de materializar el pedido: sin pasarela, ni se crea el pedido ni se vacía el carrito.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => PaymentGatewaySetting::query()->delete());

    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);

    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(422);

    $this->getJson('/t/fonda-tienda/orders')->assertOk()->assertJsonCount(0, 'data'); // no hay pedido huérfano
    $this->getJson('/t/fonda-tienda/cart')->assertOk()->assertJsonCount(1, 'data.items'); // el carrito sigue intacto
});

it('el folio de pedidos es consecutivo sin huecos', function () {
    $this->actingAs($this->customer, 'customer');

    foreach (['WEB-000001', 'WEB-000002'] as $expected) {
        $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
        $folio = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.folio');
        expect($folio)->toBe($expected);
    }
});

it('el cliente sólo ve sus pedidos', function () {
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $ulid = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');

    $this->getJson('/t/fonda-tienda/orders')->assertOk()->assertJsonCount(1, 'data');

    // Otro cliente no ve ese pedido.
    $otro = app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Beto', 'phone' => '5599990000', 'email' => 'beto@correo.mx', 'password' => 'contrasena-larga']));
    $this->actingAs($otro, 'customer');

    $this->getJson('/t/fonda-tienda/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/t/fonda-tienda/orders/{$ulid}")->assertNotFound();
});

it('las zonas de envío: admin las gestiona (gateado por módulo)', function () {
    // Público: lista de zonas activas.
    $this->getJson('/t/fonda-tienda/shipping-zones')->assertOk()->assertJsonPath('data.0.name', 'Centro');

    // Admin: crear una zona exige el módulo y el permiso.
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/shipping-zones', ['name' => 'Norte', 'cost' => '70.00', 'is_active' => true])
        ->assertStatus(201)->assertJsonPath('data.name', 'Norte')
        ->json('data.ulid');

    // Editarla…
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/shipping-zones/{$ulid}", ['name' => 'Norte Alto', 'cost' => '90.00', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.name', 'Norte Alto')->assertJsonPath('data.cost', '90.00');

    // …y borrarla.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/shipping-zones/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/shipping-zones')
        ->assertOk()->assertJsonMissing(['name' => 'Norte Alto']);
});

it('sin el módulo, la gestión de zonas se rechaza (403)', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('Ecommerce', false));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/shipping-zones')
        ->assertStatus(403);
});

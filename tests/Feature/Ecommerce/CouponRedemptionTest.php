<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\CouponRedemption;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CANJE DE CUPONES EN EL CHECKOUT (Iteración 8, Tanda D, D3 parte 2)
 *
 * El cliente teclea un código; se valida (activo, vigente, bajo topes) y descuenta. Un %/monto reduce productos; el envío
 * gratis pone el envío en cero. El canje se registra inmutable, cuenta usos, y la venta se asienta NETA de cupones (D342).
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);
        Warehouse::factory()->create(['branch_id' => $this->branch->id]);

        $this->article = Article::create([
            'name' => 'Cerveza', 'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true, 'is_inventoriable' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        $this->store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $this->store->storeBranches()->create(['branch_id' => $this->branch->id]);
        $this->zone = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Centro', 'cost' => '50.00', 'is_active' => true]);
        PaymentGatewaySetting::create(['active_gateway' => 'fake']);
        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

function coupon(array $attrs): void
{
    app(TenantContext::class)->runFor(test()->tenant->id, fn () => Coupon::create($attrs + ['store_id' => test()->store->id, 'is_active' => true]));
}

function addTwoToCart(): void
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/fonda-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 2])->assertStatus(201);
}

it('un cupón de porcentaje descuenta los productos y registra el canje', function () {
    coupon(['code' => 'CUARTO', 'type' => 'percentage', 'value' => '25']);
    addTwoToCart();

    $order = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'CUARTO'])
        ->assertStatus(201)->json('data');

    expect($order['subtotal'])->toBe('200.00');
    expect($order['discount_total'])->toBe('50.00'); // 25% de 200
    expect($order['total'])->toBe('150.00');

    app(TenantContext::class)->set($this->tenant->id);
    expect(CouponRedemption::query()->count())->toBe(1);
    expect(Coupon::query()->where('code', 'CUARTO')->value('uses_count'))->toBe(1);
});

it('un monto fijo no descuenta más que los productos', function () {
    coupon(['code' => 'MENOS30', 'type' => 'fixed', 'value' => '30']);
    addTwoToCart();

    $order = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'MENOS30'])
        ->assertStatus(201)->json('data');

    expect($order['discount_total'])->toBe('30.00');
    expect($order['total'])->toBe('170.00');
});

it('el envío gratis pone el envío en cero y exige entrega a domicilio', function () {
    coupon(['code' => 'ENVIOGRATIS', 'type' => 'free_shipping', 'value' => '0']);

    // En pickup no aplica.
    addTwoToCart();
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'ENVIOGRATIS'])->assertStatus(422);

    // Con envío, el costo del envío queda en cero.
    $order = $this->postJson('/t/fonda-tienda/checkout', [
        'delivery_type' => 'shipping', 'zone_ulid' => $this->zone->ulid, 'address' => 'Calle 1', 'coupon_code' => 'ENVIOGRATIS',
    ])->assertStatus(201)->json('data');

    expect($order['shipping_cost'])->toBe('0.00');
    expect($order['total'])->toBe('200.00'); // productos, sin envío
});

it('al pagar, la venta en el diario es neta del cupón', function () {
    coupon(['code' => 'CUARTO', 'type' => 'percentage', 'value' => '25']);
    addTwoToCart();
    $ulid = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'CUARTO'])->assertStatus(201)->json('data.ulid');
    $this->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '150.00'])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(FinancialMovement::query()->where('type', 'online_sale')->sole()->amount)->toBe('150.00'); // 200 − 50
});

it('un cupón inexistente, inactivo o fuera de tope se rechaza', function () {
    addTwoToCart();
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'NOEXISTE'])->assertStatus(422);

    coupon(['code' => 'APAGADO', 'type' => 'percentage', 'value' => '10', 'is_active' => false]);
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'APAGADO'])->assertStatus(422);
});

it('el tope global y el límite por cliente se respetan', function () {
    coupon(['code' => 'UNAVEZ', 'type' => 'percentage', 'value' => '10', 'max_uses' => 1, 'per_customer_limit' => 1]);

    addTwoToCart();
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'UNAVEZ'])->assertStatus(201);

    // Un segundo intento (mismo cliente, y además el tope global es 1) se rechaza.
    addTwoToCart();
    $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup', 'coupon_code' => 'UNAVEZ'])->assertStatus(422);
});

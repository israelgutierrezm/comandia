<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * FLUJO DE PAGO + CICLO FINANCIERO (Iteración 8, Tanda C parte 3a)
 *
 * Con la pasarela de prueba: checkout → cobro iniciado → webhook aprobado → pago (inmutable, idempotente) + pedido `paid`
 * + `EcommerceOrderPaid` → Finance asienta la venta (canal e-commerce, sin caja) e Inventory descuenta. ADR-004: el
 * e-commerce no escribe en finanzas ni en el kardex; emite el hecho.
 */
const ORDER_SOURCE = 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Order';

beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        Warehouse::factory()->create(['branch_id' => $this->branch->id]); // para el descuento de inventario

        $this->article = Article::create([
            'name' => 'Cerveza', 'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true, 'is_inventoriable' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);

        PaymentGatewaySetting::create(['active_gateway' => 'fake']);

        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

function placeAnOrder(): string
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/fonda-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 2])->assertStatus(201);
    $res = test()->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201);

    expect($res->json('payment_url'))->toContain('/fake-pay/'); // inició el cobro con la pasarela

    return $res->json('data.ulid');
}

it('el webhook aprobado paga el pedido, crea el pago y asienta la venta y el inventario', function () {
    $ulid = placeAnOrder();

    $this->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '200.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // El pedido quedó pagado y hay un pago inmutable.
    expect(Order::query()->where('ulid', $ulid)->value('status'))->toBe('paid');
    expect(Payment::query()->whereHas('order', fn ($q) => $q->where('ulid', $ulid))->count())->toBe(1);

    // Finance asentó la VENTA EN LÍNEA (tipo propio, sin sesión de caja — ADR-010) por el subtotal.
    $sale = FinancialMovement::query()->where('source_type', ORDER_SOURCE)->where('source_ulid', $ulid)->where('type', 'online_sale')->first();
    expect($sale)->not->toBeNull();
    expect($sale->amount)->toBe('200.00');
    expect($sale->actor_membership_id)->toBeNull();   // asiento automático
    expect($sale->pos_session_id)->toBeNull();        // sin caja

    // Inventory descontó (la cola corre inline en pruebas).
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeTrue();
});

it('el webhook es idempotente: un aviso repetido no duplica pago ni venta', function () {
    $ulid = placeAnOrder();

    $payload = ['reference' => $ulid, 'approved' => 1, 'amount' => '200.00'];
    $this->postJson('/t/fonda-tienda/webhook/fake', $payload)->assertOk();
    $this->postJson('/t/fonda-tienda/webhook/fake', $payload)->assertOk(); // repetido

    app(TenantContext::class)->set($this->tenant->id);
    expect(Payment::query()->count())->toBe(1);
    expect(FinancialMovement::query()->where('source_ulid', $ulid)->where('type', 'online_sale')->count())->toBe(1);
});

it('un webhook no aprobado deja el pedido pendiente', function () {
    $ulid = placeAnOrder();

    $this->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 0])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('ulid', $ulid)->value('status'))->toBe('pending_payment');
    expect(Payment::query()->count())->toBe(0);
});

it('el admin configura la pasarela; el secreto nunca vuelve por la API', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/payment-gateway', ['active_gateway' => 'stripe', 'public_key' => 'pk_test', 'secret_key' => 'sk_secreto', 'webhook_secret' => 'wh_secreto'])
        ->assertOk()
        ->assertJsonPath('data.active_gateway', 'stripe')
        ->assertJsonPath('data.public_key', 'pk_test')
        ->assertJsonPath('data.has_secret_key', true)
        ->assertJsonMissing(['secret_key' => 'sk_secreto']);
});

it('sin el módulo, configurar la pasarela se rechaza (403)', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('Ecommerce', false));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/payment-gateway')
        ->assertStatus(403);
});

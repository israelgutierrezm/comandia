<?php

declare(strict_types=1);

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Domain\Payments\CheckoutIntent;
use App\Modules\Ecommerce\Domain\Payments\PaymentGateway;
use App\Modules\Ecommerce\Domain\Payments\RefundResult;
use App\Modules\Ecommerce\Domain\Payments\WebhookResult;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\CouponRedemption;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Ecommerce\Infrastructure\Payments\FakeGateway;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Http\Request;

/**
 * PEDIDOS EN LÍNEA QUE NADIE PAGÓ (D380)
 *
 * Un pedido nacía «pendiente de pago» y, si el cliente se iba sin pagar, se quedaba así para siempre, con su cupón
 * GASTADO: el canje se registra al colocar el pedido. Y si la pasarela cobraba tarde, el pago no quedaba en ningún lado.
 *
 * Ahora: a los 45 minutos sin pago el pedido pasa a «Pago vencido» y devuelve su cupón, y un pago que llegue después se
 * registra y se reembolsa solo.
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
        PaymentGatewaySetting::create(['active_gateway' => 'fake']);
        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);

        // El cupón de «primer pedido»: una vez por cliente, y sólo diez en total.
        Coupon::create([
            'store_id' => $this->store->id, 'code' => 'BIENVENIDA', 'type' => 'percentage', 'value' => '10',
            'is_active' => true, 'per_customer_limit' => 1, 'max_uses' => 10,
        ]);
    });
    app(TenantContext::class)->forget();

    /** Coloca un pedido de dos cervezas, con o sin cupón, y devuelve su ULID. */
    $this->pedir = function (?string $cupon = null) {
        $this->actingAs($this->customer, 'customer');
        $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 2])
            ->assertStatus(201);

        return $this->postJson('/t/fonda-tienda/checkout', array_filter(['delivery_type' => 'pickup', 'coupon_code' => $cupon]));
    };

    $this->estado = fn (string $ulid): string => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => Order::query()->where('ulid', $ulid)->sole()->status->value,
    );

    $this->usos = fn (): int => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => (int) Coupon::query()->where('code', 'BIENVENIDA')->value('uses_count'),
    );
});

afterEach(function () {
    app(TenantContext::class)->forget();
    $this->travelBack();
});

it('a los 45 minutos sin pago el pedido pasa a «Pago vencido» y DEVUELVE su cupón', function () {
    $ulid = ($this->pedir)('BIENVENIDA')->assertStatus(201)->json('data.ulid');

    expect(($this->usos)())->toBe(1);

    $this->travel(46)->minutes();
    $this->artisan('comandia:online-orders:expire')->assertSuccessful();

    expect(($this->estado)($ulid))->toBe('expired');

    app(TenantContext::class)->set($this->tenant->id);

    expect(Order::query()->where('ulid', $ulid)->sole()->expired_at)->not->toBeNull();
    expect(CouponRedemption::query()->sole()->released_at)->not->toBeNull();
    expect(($this->usos)())->toBe(0);

    app(TenantContext::class)->forget();

    // Y el cliente vuelve a poder usar su cupón de una sola vez: antes, el carrito abandonado se lo había gastado.
    ($this->pedir)('BIENVENIDA')->assertStatus(201);
});

it('un pedido con menos de 45 minutos sigue esperando su pago', function () {
    $ulid = ($this->pedir)('BIENVENIDA')->json('data.ulid');

    $this->travel(44)->minutes();
    $this->artisan('comandia:online-orders:expire')->assertSuccessful();

    expect(($this->estado)($ulid))->toBe('pending_payment');
    expect(($this->usos)())->toBe(1);
});

it('un pedido PAGADO no vence', function () {
    $ulid = ($this->pedir)()->json('data.ulid');

    $this->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1])->assertOk();

    $this->travel(2)->hours();
    $this->artisan('comandia:online-orders:expire')->assertSuccessful();

    expect(($this->estado)($ulid))->toBe('paid');
});

it('el pago que llega a un pedido VENCIDO se registra y se reembolsa solo, sin asentar la venta', function () {
    $ulid = ($this->pedir)()->json('data.ulid');

    $this->travel(46)->minutes();
    $this->artisan('comandia:online-orders:expire')->assertSuccessful();

    $aviso = ['reference' => $ulid, 'approved' => 1];

    $this->postJson('/t/fonda-tienda/webhook/fake', $aviso)->assertOk()->assertJsonPath('status', 'expired');

    // Un aviso repetido no reembolsa dos veces.
    $this->postJson('/t/fonda-tienda/webhook/fake', $aviso)->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(Order::query()->where('ulid', $ulid)->sole()->status->value)->toBe('expired');
    expect(Payment::query()->where('status', 'approved')->count())->toBe(1)
        ->and(Payment::query()->where('status', 'refunded')->count())->toBe(1);

    // La venta nunca se asentó: lo que entró por la pasarela salió por ella.
    expect(FinancialMovement::query()->where('source_ulid', $ulid)->count())->toBe(0);

    expect(AuditEntry::query()->where('action', 'ecommerce.late_payment_refunded')->count())->toBe(1);
});

it('si la pasarela no puede reembolsar, queda en la bitácora y el aviso falla para que se reintente', function () {
    $ulid = ($this->pedir)()->json('data.ulid');

    $this->travel(46)->minutes();
    $this->artisan('comandia:online-orders:expire')->assertSuccessful();

    // Una pasarela que cobra pero no puede devolver.
    app()->instance(FakeGateway::class, new class(new FakeGateway) implements PaymentGateway
    {
        public function __construct(private readonly FakeGateway $real) {}

        public function name(): string
        {
            return 'fake';
        }

        public function createCheckout(Order $order, PaymentGatewaySetting $settings): CheckoutIntent
        {
            return $this->real->createCheckout($order, $settings);
        }

        public function parseWebhook(Request $request, PaymentGatewaySetting $settings): WebhookResult
        {
            return $this->real->parseWebhook($request, $settings);
        }

        public function refund(Order $order, Payment $payment, PaymentGatewaySetting $settings): RefundResult
        {
            throw new RuntimeException('La pasarela no respondió.');
        }
    });

    $aviso = ['reference' => $ulid, 'approved' => 1];

    // Error: la pasarela vuelve a mandar el aviso más tarde.
    $this->postJson('/t/fonda-tienda/webhook/fake', $aviso)->assertStatus(500);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(Payment::query()->where('status', 'approved')->count())->toBe(1)
            ->and(Payment::query()->where('status', 'refunded')->count())->toBe(0);
        expect(AuditEntry::query()->where('action', 'ecommerce.late_payment_refund_failed')->count())->toBe(1);
    });

    // La pasarela se recupera y reintenta el aviso: sólo falta el reembolso, y se hace.
    app()->forgetInstance(FakeGateway::class);

    $this->postJson('/t/fonda-tienda/webhook/fake', $aviso)->assertOk();

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(Payment::query()->where('status', 'approved')->count())->toBe(1)
            ->and(Payment::query()->where('status', 'refunded')->count())->toBe(1);
    });
});

it('la tarea respeta a cada negocio: con --tenant sólo vence los de ése', function () {
    $ulid = ($this->pedir)()->json('data.ulid');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería', ownerEmail: 'luis@cafe.mx', ownerFirstName: 'Luis', ownerPaternalSurname: 'Ríos', plainPassword: 'secreto-largo-456',
    )['tenant'];

    $this->travel(46)->minutes();
    $this->artisan('comandia:online-orders:expire', ['--tenant' => $otro->slug])->assertSuccessful();

    expect(($this->estado)($ulid))->toBe('pending_payment');

    $this->artisan('comandia:online-orders:expire', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    expect(($this->estado)($ulid))->toBe('expired');
});

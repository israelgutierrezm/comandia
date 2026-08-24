<?php

declare(strict_types=1);

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Application\PaymentProcessor;
use App\Modules\Ecommerce\Application\RejectOrder;
use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Ecommerce\Infrastructure\Payments\MercadoPagoGateway;
use App\Modules\Ecommerce\Infrastructure\Payments\StripeGateway;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * PASARELAS REALES: MERCADO PAGO Y STRIPE (Iteración 8, Tanda C parte 3b)
 *
 * Ambas implementan el MISMO contrato que la de prueba. Se doblan con `Http::fake` —sin red—: se verifica que el checkout
 * arma el cobro correcto y que el webhook **verifica su firma** (D55) antes de creer nada. La referencia que casa el pago
 * con el pedido es el ULID del pedido, en `client_reference_id` (Stripe) / `external_reference` (Mercado Pago).
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
    $store->storeBranches()->create(['branch_id' => $this->branch->id]);
    $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);

    $this->order = Order::create([
        'store_id' => $store->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'series' => 'WEB', 'order_number' => 1, 'delivery_type' => 'pickup', 'shipping_cost' => '0.00',
        'subtotal' => '183.00', 'total' => '183.00', 'status' => 'pending_payment', 'placed_at' => now(),
    ])->refresh();

    // Un solo juego de secretos para las dos pasarelas: cada prueba llama a la que le toca.
    $this->settings = PaymentGatewaySetting::create([
        'active_gateway' => 'stripe', 'public_key' => 'pk_test', 'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test',
    ]);
});

afterEach(fn () => app(TenantContext::class)->forget());

// ---------------------------------------------------------------------------
// Stripe
// ---------------------------------------------------------------------------

it('stripe: crea la sesión de checkout con el monto en centavos y manda a su URL', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_123', 'url' => 'https://checkout.stripe.com/c/cs_123'])]);

    $intent = app(StripeGateway::class)->createCheckout($this->order->load('store'), $this->settings);

    expect($intent->redirectUrl)->toBe('https://checkout.stripe.com/c/cs_123');
    expect($intent->reference)->toBe($this->order->ulid);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/v1/checkout/sessions')
        && $req['client_reference_id'] === $this->order->ulid
        && (int) $req['line_items[0][price_data][unit_amount]'] === 18300); // 183.00 → centavos
});

it('stripe: un webhook firmado y pagado se traduce a aprobado', function () {
    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['client_reference_id' => $this->order->ulid, 'payment_status' => 'paid', 'amount_total' => 18300]],
    ], JSON_THROW_ON_ERROR);

    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'whsec_test');
    $req = signedGatewayWebhookRequest($body, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}"]);

    $result = app(StripeGateway::class)->parseWebhook($req, $this->settings);

    expect($result->approved)->toBeTrue();
    expect($result->reference)->toBe($this->order->ulid);
    expect($result->amount)->toBe('183.00');
});

it('stripe: un webhook con firma inválida se rechaza', function () {
    $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
    $req = signedGatewayWebhookRequest($body, ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=firma-falsa']);

    expect(fn () => app(StripeGateway::class)->parseWebhook($req, $this->settings))->toThrow(BadRequestHttpException::class);
});

it('stripe: el flujo completo por el procesador deja el pedido pagado y asienta la venta en línea', function () {
    // Como lo dejaría `initiate()`: la referencia guardada es el ULID del pedido.
    $this->order->update(['gateway' => 'stripe', 'gateway_reference' => $this->order->ulid]);

    $body = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['client_reference_id' => $this->order->ulid, 'payment_status' => 'paid', 'amount_total' => 18300]],
    ], JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'whsec_test');
    $req = signedGatewayWebhookRequest($body, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}"]);

    $paid = app(PaymentProcessor::class)->confirm('stripe', $req);

    expect($paid)->not->toBeNull();
    expect($paid->status)->toBe(OnlineOrderStatus::Paid);
    expect(Payment::query()->where('order_id', $this->order->id)->where('gateway', 'stripe')->count())->toBe(1);
    expect(FinancialMovement::query()->where('source_ulid', $this->order->ulid)->where('type', 'online_sale')->value('amount'))->toBe('183.00');
});

// ---------------------------------------------------------------------------
// Mercado Pago
// ---------------------------------------------------------------------------

it('mercadopago: crea la preferencia con la referencia externa y manda a su init_point', function () {
    Http::fake(['api.mercadopago.com/checkout/preferences' => Http::response(['id' => 'pref_1', 'init_point' => 'https://mp.com/checkout/pref_1'])]);

    $intent = app(MercadoPagoGateway::class)->createCheckout($this->order->load('store'), $this->settings);

    expect($intent->redirectUrl)->toBe('https://mp.com/checkout/pref_1');
    expect($intent->reference)->toBe($this->order->ulid);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/checkout/preferences')
        && $req['external_reference'] === $this->order->ulid);
});

it('mercadopago: consulta el pago firmado y lo traduce a aprobado', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*' => Http::response([
        'external_reference' => $this->order->ulid, 'status' => 'approved', 'transaction_amount' => 183.0,
    ])]);

    $paymentId = '123456';
    $ts = (string) time();
    $reqId = 'req-abc';
    $manifest = "id:{$paymentId};request-id:{$reqId};ts:{$ts};";
    $sig = hash_hmac('sha256', $manifest, 'whsec_test');
    $body = json_encode(['type' => 'payment', 'data' => ['id' => $paymentId]], JSON_THROW_ON_ERROR);
    $req = signedGatewayWebhookRequest($body, ['HTTP_X_SIGNATURE' => "ts={$ts},v1={$sig}", 'HTTP_X_REQUEST_ID' => $reqId]);

    $result = app(MercadoPagoGateway::class)->parseWebhook($req, $this->settings);

    expect($result->approved)->toBeTrue();
    expect($result->reference)->toBe($this->order->ulid);
    expect($result->amount)->toBe('183.00');
});

it('mercadopago: una firma inválida se rechaza sin siquiera consultar el pago', function () {
    Http::fake(['api.mercadopago.com/*' => Http::response([])]);

    $body = json_encode(['type' => 'payment', 'data' => ['id' => '123']], JSON_THROW_ON_ERROR);
    $req = signedGatewayWebhookRequest($body, ['HTTP_X_SIGNATURE' => 'ts='.time().',v1=firma-falsa', 'HTTP_X_REQUEST_ID' => 'r']);

    expect(fn () => app(MercadoPagoGateway::class)->parseWebhook($req, $this->settings))->toThrow(BadRequestHttpException::class);

    Http::assertNothingSent(); // la firma se verifica ANTES de consultar el pago
});

// ---------------------------------------------------------------------------
// Reembolsos reales (Tanda D, D2 parte 2)
// ---------------------------------------------------------------------------

it('stripe: reembolsa contra el payment_intent del cargo', function () {
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_123', 'amount' => 18300, 'status' => 'succeeded'])]);

    $payment = new Payment(['gateway_payment_id' => 'pi_abc', 'amount' => '183.00']);
    $refund = app(StripeGateway::class)->refund($this->order->load('store'), $payment, $this->settings);

    expect($refund->reference)->toBe('re_123');
    expect($refund->amount)->toBe('183.00');
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/v1/refunds') && $req['payment_intent'] === 'pi_abc');
});

it('mercadopago: reembolsa contra el id del pago', function () {
    Http::fake(['api.mercadopago.com/v1/payments/*/refunds' => Http::response(['id' => 987654, 'amount' => 183.0])]);

    $payment = new Payment(['gateway_payment_id' => '111222', 'amount' => '183.00']);
    $refund = app(MercadoPagoGateway::class)->refund($this->order->load('store'), $payment, $this->settings);

    expect($refund->reference)->toBe('987654');
    expect($refund->amount)->toBe('183.00');
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/v1/payments/111222/refunds'));
});

it('stripe: confirmar guarda el payment_intent y el rechazo reembolsa contra él', function () {
    $this->order->update(['gateway' => 'stripe', 'gateway_reference' => $this->order->ulid]);

    // El webhook trae el payment_intent del cargo: se guarda en el pago.
    $body = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
        'client_reference_id' => $this->order->ulid, 'payment_status' => 'paid', 'amount_total' => 18300, 'payment_intent' => 'pi_abc',
    ]]], JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts.'.'.$body, 'whsec_test');
    app(PaymentProcessor::class)->confirm('stripe', signedGatewayWebhookRequest($body, ['HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$sig}"]));

    expect(Payment::query()->where('order_id', $this->order->id)->where('status', 'approved')->value('gateway_payment_id'))->toBe('pi_abc');

    // Rechazar reembolsa por Stripe contra ese payment_intent.
    Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'amount' => 18300])]);
    $rejected = app(RejectOrder::class)->reject($this->order->refresh(), 'Sin stock');

    expect($rejected->status)->toBe(OnlineOrderStatus::Rejected);
    expect(Payment::query()->where('order_id', $this->order->id)->where('status', 'refunded')->count())->toBe(1);
    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/v1/refunds') && $req['payment_intent'] === 'pi_abc');
});

/** Arma un Request POST con cuerpo JSON crudo y los encabezados de firma dados (el cuerpo crudo importa para el HMAC). */
function signedGatewayWebhookRequest(string $body, array $headers): Request
{
    return Request::create('/t/fonda-tienda/webhook/x', 'POST', [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
    ], $headers), $body);
}

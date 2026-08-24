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
 * RECHAZO + REEMBOLSO + ENTREGA (Iteración 8, Tanda D, D2)
 *
 * Rechazar un pedido **pagado y sin aceptar** lo reembolsa (pasarela de prueba), asienta la reversa de la `OnlineSale` en el
 * diario (ADR-010 regla 4) y NO toca el inventario —nunca se aceptó, así que nunca se descontó (D338)—. Los estados de
 * entrega avanzan `accepted → ready → completed`; los saltos ilegales se rechazan.
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
        Warehouse::factory()->create(['branch_id' => $this->branch->id]);

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

/** Coloca un pedido de 2 piezas y lo paga; devuelve el ULID y repone el guard del personal. */
function placePaidRejectionOrder(): string
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/fonda-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 2])->assertStatus(201);
    $ulid = test()->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');
    test()->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '200.00'])->assertOk();
    auth()->shouldUse('web');

    return $ulid;
}

it('rechazar un pedido pagado lo reembolsa y reversa la venta en el diario', function () {
    $ulid = placePaidRejectionOrder();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/reject", ['reason' => 'Sin ingredientes'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    app(TenantContext::class)->set($this->tenant->id);
    $order = Order::query()->where('ulid', $ulid)->sole();
    expect($order->status->value)->toBe('rejected');
    expect($order->rejection_reason)->toBe('Sin ingredientes');
    expect($order->rejected_at)->not->toBeNull();

    // Un pago de reembolso inmutable, aparte del aprobado.
    expect(Payment::query()->where('order_id', $order->id)->where('status', 'refunded')->count())->toBe(1);

    // El diario: la venta (+200) y su reversa (−200) → el neto de ventas en línea es cero.
    $onlineSales = FinancialMovement::query()->where('type', 'online_sale')->get();
    expect($onlineSales)->toHaveCount(2);
    expect($onlineSales->pluck('amount')->sort()->values()->all())->toBe(['-200.00', '200.00']);
    expect($onlineSales->firstWhere(fn ($m): bool => $m->amount === '-200.00')->reverses_movement_id)->not->toBeNull();

    // Nunca se aceptó, así que el inventario nunca se movió.
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeFalse();
});

it('no se puede rechazar un pedido pendiente ni uno ya aceptado', function () {
    // Pendiente de pago.
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $pendiente = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');
    auth()->shouldUse('web');
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$pendiente}/reject", ['reason' => 'x'])->assertStatus(422);

    // Ya aceptado: rechazar deja de ser legal (la cocina ya lo tiene).
    $ulid = placePaidRejectionOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/reject", ['reason' => 'x'])->assertStatus(422);
});

it('el pedido avanza accepted → ready → completed; los saltos ilegales se rechazan', function () {
    $ulid = placePaidRejectionOrder();

    // No se puede marcar listo un pedido que aún no se acepta.
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/ready")->assertStatus(422);

    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/ready")->assertOk()->assertJsonPath('data.status', 'ready');
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

    // Completado es terminal.
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/complete")->assertStatus(422);
});

it('sin el módulo, el rechazo se rechaza (403)', function () {
    $ulid = placePaidRejectionOrder();
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('Ecommerce', false));

    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/reject", ['reason' => 'x'])->assertStatus(403);
});

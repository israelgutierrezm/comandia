<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
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
 * BANDEJA DE ACEPTACIÓN (Iteración 8, Tanda D parte 1, D51)
 *
 * Un pedido pagado espera en la bandeja. Aceptarlo lo compromete a prepararse: **ahí** se descuenta el inventario (un
 * pedido rechazado nunca mueve stock) y se congela el área de cada línea vía la sonda `AreaRouter` del kernel (Pos resuelve
 * el ruteo sin que Ecommerce lo nombre). La aceptación puede ser automática (D51). Las transiciones ilegales se rechazan.
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

        Warehouse::factory()->create(['branch_id' => $this->branch->id]); // almacén de la sucursal

        // Un área de cocina con SU almacén, y una regla de ruteo por categoría → área.
        $areaWarehouse = Warehouse::factory()->create(['branch_id' => $this->branch->id, 'kind' => WarehouseKind::Branch]);
        $this->area = PreparationArea::create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $areaWarehouse->id, 'code' => 'COC', 'name' => 'Cocina',
        ]);
        $this->areaWarehouseId = $areaWarehouse->id;

        $category = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);
        PosAreaRoute::create([
            'branch_id' => $this->branch->id, 'article_category_id' => $category->id, 'preparation_area_id' => $this->area->id,
        ]);

        $this->article = Article::create([
            'name' => 'Enchiladas', 'category_id' => $category->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true, 'is_inventoriable' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        $this->store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $this->store->storeBranches()->create(['branch_id' => $this->branch->id]);

        PaymentGatewaySetting::create(['active_gateway' => 'fake']);
        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** Coloca un pedido de 2 piezas y lo paga por la pasarela de prueba; devuelve el ULID del pedido. */
function placePaidOrder(): string
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/fonda-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 2])->assertStatus(201);
    $ulid = test()->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');
    test()->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '200.00'])->assertOk();

    // Autenticar al cliente dejó `customer` como guard por omisión; se repone `web` para que el `actingAsSpa` del personal
    // que sigue autentique en el guard que lee `auth:sanctum`.
    auth()->shouldUse('web');

    return $ulid;
}

it('la sonda AreaRouter congela el área de cada línea al hacer el pedido', function () {
    $ulid = placePaidOrder();

    app(TenantContext::class)->set($this->tenant->id);
    $item = Order::query()->where('ulid', $ulid)->sole()->items()->sole();
    expect((int) $item->preparation_area_id)->toBe((int) $this->area->id); // ruteado por su categoría
});

it('un pedido pagado NO descuenta inventario hasta que se acepta', function () {
    placePaidOrder();

    app(TenantContext::class)->set($this->tenant->id);
    // Pagado pero sin aceptar: nada se movió del kardex.
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeFalse();
});

it('aceptar descuenta el inventario del almacén del área y sella al actor', function () {
    $ulid = placePaidOrder();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$ulid}/accept")
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    app(TenantContext::class)->set($this->tenant->id);
    $order = Order::query()->where('ulid', $ulid)->sole();
    expect($order->status->value)->toBe('accepted');
    expect($order->accepted_at)->not->toBeNull();
    expect($order->accepted_by_membership_id)->not->toBeNull(); // aceptación manual: queda el actor

    // Descontó, y del almacén del ÁREA (no del de la sucursal), gracias al área congelada.
    $mov = StockMovement::query()->where('article_id', $this->article->id)->first();
    expect($mov)->not->toBeNull();
    expect((int) $mov->warehouse_id)->toBe($this->areaWarehouseId);
});

it('con aceptación automática, pagar acepta y descuenta de una vez', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['auto_accept_orders' => true]));

    $ulid = placePaidOrder();

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('ulid', $ulid)->sole()->status->value)->toBe('accepted'); // se auto-aceptó al pagar
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeTrue();
});

it('no se puede aceptar un pedido que no está pagado, ni aceptarlo dos veces', function () {
    // Un pedido recién colocado (pending_payment) no se puede aceptar.
    $this->actingAs($this->customer, 'customer');
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->article->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);
    $pendiente = $this->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');
    auth()->shouldUse('web'); // repone el guard del personal tras autenticar al cliente

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/orders/{$pendiente}/accept")
        ->assertStatus(422); // pending_payment → accepted es ilegal

    // Un pedido pagado se acepta una vez; la segunda choca con la máquina de estados.
    $ulid = placePaidOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertStatus(422);
});

it('la bandeja lista los pedidos y filtra por estado', function () {
    $ulid = placePaidOrder();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/orders?status=paid')
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $ulid)
        ->assertJsonPath('data.0.status', 'paid');

    // No hay ninguno aceptado todavía.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/orders?status=accepted')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('sin el módulo, la bandeja y la aceptación se rechazan (403)', function () {
    $ulid = placePaidOrder();
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('Ecommerce', false));

    $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/orders')->assertStatus(403);
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertStatus(403);
});

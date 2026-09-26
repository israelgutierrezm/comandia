<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * INGESTA DE MARKETPLACE (ADR-015, Fase 1) — DiDi Food / Uber Eats / Rappi por el adaptador de PRUEBAS.
 *
 * Un pedido de marketplace entra por webhook, se materializa como `Order` (delivery_type=marketplace), se
 * marca pagado (la plataforma cobró) y se auto-acepta: reusa el pipeline (inventario + comanda por área) y
 * asienta la venta en línea neteada por la comisión del canal. Idempotente por (canal, id externo).
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con apps', ownerEmail: 'duena@apps.mx', ownerFirstName: 'Rosa', ownerPaternalSurname: 'Lima', plainPassword: 'contrasena-larga-1',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        Warehouse::factory()->create(['branch_id' => $this->branch->id]); // almacén de la sucursal

        // Un área con regla de ruteo: la comida SÍ se rutea a cocina (modo preparación, a diferencia de envío).
        $areaWarehouse = Warehouse::factory()->create(['branch_id' => $this->branch->id]);
        $this->area = PreparationArea::create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $areaWarehouse->id, 'code' => 'COC', 'name' => 'Cocina',
        ]);
        $category = ArticleCategory::create(['name' => 'Fuertes', 'level' => 1]);
        PosAreaRoute::create([
            'branch_id' => $this->branch->id, 'article_category_id' => $category->id, 'preparation_area_id' => $this->area->id,
        ]);

        $this->article = Article::create([
            'name' => 'Enchiladas', 'category_id' => $category->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true, 'base_price' => '100.00', 'is_available_in_pos' => true, 'is_inventoriable' => true,
        ]);
        ArticleStoreSetting::create(['article_id' => $this->article->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);

        $this->store = Store::create(['slug' => 'fonda-apps', 'name' => 'Fonda', 'is_active' => true]);
        $this->store->storeBranches()->create(['branch_id' => $this->branch->id]);

        // Canal de PRUEBAS encendido, con 20% de comisión, y el mapeo del ítem externo al artículo.
        DeliveryChannelSetting::create([
            'branch_id' => $this->branch->id, 'channel' => 'fake', 'is_active' => true, 'commission_rate' => '20.00',
        ]);
        MarketplaceMenuMap::create(['channel' => 'fake', 'external_item_id' => 'EXT-ENCH', 'article_id' => $this->article->id]);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** Cuerpo de un pedido de marketplace de prueba. */
function marketplaceBody(string $externalId = 'DIDI-1', int $qty = 2): array
{
    return [
        'external_order_id' => $externalId,
        'customer_name' => 'Laura del Marketplace',
        'customer_phone' => '5599887766',
        'notes' => 'Sin cebolla',
        'items' => [
            ['external_item_id' => 'EXT-ENCH', 'quantity' => $qty],
        ],
    ];
}

it('ingiere un pedido de marketplace: lo deja aceptado, ruteado a cocina y descuenta inventario', function () {
    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', marketplaceBody())
        ->assertOk()
        ->assertJsonPath('status', 'received');

    app(TenantContext::class)->set($this->tenant->id);
    $order = Order::query()->where('channel', 'fake')->where('external_order_id', 'DIDI-1')->sole();

    expect($order->tenant_id)->toBe($this->tenant->id)   // aislamiento: nace en el negocio del slug
        ->and($order->delivery_type)->toBe('marketplace')
        ->and($order->status->value)->toBe('accepted')    // pagado (externo) → auto-aceptado
        ->and($order->subtotal)->toBe('200.00');          // 2 × 100.00, precio del catálogo

    // Comida: se ruteó a cocina (comanda), a diferencia del modo envío.
    expect($order->items()->sole()->preparation_area_id)->toBe($this->area->id);

    // Aceptar descontó inventario (por el evento de kernel, sin que la ingesta toque el kardex).
    expect(StockMovement::query()->where('article_id', $this->article->id)->exists())->toBeTrue();
});

it('asienta la venta en línea neteada por la comisión del marketplace', function () {
    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', marketplaceBody())->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    $order = Order::query()->where('external_order_id', 'DIDI-1')->sole();

    $sale = FinancialMovement::query()->where('source_ulid', $order->ulid)->where('type', 'online_sale')->value('amount');
    $commission = FinancialMovement::query()->where('source_ulid', $order->ulid)->where('type', 'marketplace_commission')->value('amount');

    expect($sale)->toBe('200.00')          // venta al bruto
        ->and($commission)->toBe('-40.00'); // comisión 20% en negativo → neto 160.00
});

it('es idempotente: el mismo pedido reenviado no se duplica', function () {
    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', marketplaceBody('DIDI-9'))->assertOk();
    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', marketplaceBody('DIDI-9'))->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('external_order_id', 'DIDI-9')->count())->toBe(1);
});

it('rechaza un ítem que no está mapeado a ningún artículo', function () {
    $body = marketplaceBody();
    $body['items'] = [['external_item_id' => 'NO-EXISTE', 'quantity' => 1]];

    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', $body)->assertStatus(422);

    app(TenantContext::class)->set($this->tenant->id);
    expect(Order::query()->where('channel', 'fake')->exists())->toBeFalse();
});

it('un canal apagado o sin configurar no ingiere', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => DeliveryChannelSetting::query()
        ->where('channel', 'fake')->update(['is_active' => false]));

    $this->postJson('/t/fonda-apps/webhook/marketplace/fake', marketplaceBody())->assertNotFound();
});

it('un canal real configurado pero aún no cableado responde 503, no 500', function () {
    // Configurado y encendido, pero su adaptador real no está implementado (Fase 3): 503, no 500.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => DeliveryChannelSetting::create([
        'branch_id' => $this->branch->id, 'channel' => 'didi_food', 'is_active' => true, 'commission_rate' => '0.00',
    ]));

    $this->postJson('/t/fonda-apps/webhook/marketplace/didi_food', marketplaceBody())
        ->assertStatus(503);
});

// -----------------------------------------------------------------------------------------------------
// Administración: encender/apagar y configurar cada canal por sucursal
// -----------------------------------------------------------------------------------------------------

it('configura un canal por sucursal y no expone el secreto', function () {
    $resp = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/delivery-channels', [
            'branch_ulid' => $this->branch->ulid,
            'channel' => 'didi_food',
            'is_active' => true,
            'external_store_id' => 'STORE-42',
            'commission_rate' => 18.5,
            'webhook_secret' => 'secreto-de-firma',
        ])
        ->assertOk()
        ->assertJsonPath('data.channel', 'didi_food')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.has_webhook_secret', true);

    expect(json_encode($resp->json()))->not->toContain('secreto-de-firma');

    // Y aparece en el listado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/delivery-channels')
        ->assertOk()
        ->assertJsonPath('data.0.channel', fn ($c) => in_array($c, ['fake', 'didi_food'], true));
});

it('las credenciales de la API se guardan, no salen y un guardado sin ellas las conserva', function () {
    // `$extra` primero: con `+` gana la llave de la izquierda, y así cada guardado puede sobrescribir `is_active`.
    $guardar = fn (array $extra) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/delivery-channels', $extra + [
            'branch_ulid' => $this->branch->ulid,
            'channel' => 'uber_eats',
            'is_active' => false,
        ]);

    $resp = $guardar(['api_key' => 'llave-publica-77', 'api_secret' => 'secreto-api-77'])
        ->assertOk()
        ->assertJsonPath('data.has_api_key', true)
        ->assertJsonPath('data.has_api_secret', true);

    expect(json_encode($resp->json()))->not->toContain('llave-publica-77')->not->toContain('secreto-api-77');

    // Encender el canal desde la pantalla manda los campos de credencial vacíos: vacío = conservar.
    $guardar(['is_active' => true, 'api_key' => '', 'api_secret' => ''])
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.has_api_key', true)
        ->assertJsonPath('data.has_api_secret', true);

    $setting = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => DeliveryChannelSetting::query()->where('channel', 'uber_eats')->sole(),
    );

    expect($setting->api_key)->toBe('llave-publica-77')
        ->and($setting->api_secret)->toBe('secreto-api-77');
});

it('configurar canales exige el permiso de configurar la tienda', function () {
    $empleado = User::factory()->create();
    app(TenantContext::class)->runFor($this->tenant->id, function () use ($empleado): void {
        $rol = Role::create(['name' => 'Sólo ve', 'guard_name' => 'web']);
        $rol->givePermissionTo('ecommerce.orders.view');
        $empleado->assignRole($rol);
        TenantMembership::factory()->allBranches()->create(['user_id' => $empleado->id, 'default_role_id' => $rol->id]);
    });

    $this->actingAsSpa($empleado, $this->tenant->id)
        ->putJson('/api/v1/delivery-channels', [
            'branch_ulid' => $this->branch->ulid, 'channel' => 'rappi', 'is_active' => true,
        ])
        ->assertForbidden();
});

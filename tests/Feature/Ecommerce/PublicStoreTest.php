<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TIENDA PÚBLICA + CARRITO (Iteración 8, Tanda B): `/t/{slug}`, sin autenticación.
 *
 * El slug resuelve el negocio. El catálogo respeta la política de stock por artículo (ADR-007) y usa el precio de canal si
 * está. El carrito vive en la sesión y valida stock al agregar. Termina en «listo para pagar» (el pago es la Tanda C).
 */
beforeEach(function () {
    $this->withoutVite();

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        $cat = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
        $unit = Unit::query()->where('code', 'pza')->sole()->id;

        $mk = fn (string $name, string $price) => Article::create([
            'name' => $name, 'category_id' => $cat->id, 'base_unit_id' => $unit,
            'is_sellable' => true, 'base_price' => $price, 'is_available_in_pos' => true, 'is_inventoriable' => true,
        ]);

        $this->siempre = $mk('Cerveza', '60.00');   // sell_always, sin stock → se muestra
        $this->oculto = $mk('Vino', '200.00');       // hide, sin stock → NO se muestra
        $this->agotado = $mk('Mezcal', '150.00');    // mark_out_of_stock, sin stock → «agotado»
        $this->canal = $mk('Refresco', '30.00');     // sell_always, precio de canal 25

        ArticleStoreSetting::create(['article_id' => $this->siempre->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);
        ArticleStoreSetting::create(['article_id' => $this->oculto->id, 'is_in_store' => true, 'stock_policy' => 'hide']);
        ArticleStoreSetting::create(['article_id' => $this->agotado->id, 'is_in_store' => true, 'stock_policy' => 'mark_out_of_stock']);
        ArticleStoreSetting::create(['article_id' => $this->canal->id, 'is_in_store' => true, 'stock_policy' => 'sell_always', 'channel_price' => '25.00']);

        $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda en línea', 'is_active' => true, 'theme_primary' => '#c2410c']);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('sirve el shell público con su título', function () {
    $this->get('/t/fonda-tienda')->assertOk()->assertSee('Fonda en línea');
});

it('el catálogo respeta la política de stock y el precio de canal', function () {
    $catalog = $this->getJson('/t/fonda-tienda/catalog')
        ->assertOk()
        ->json('data.catalog');

    $items = collect($catalog)->flatMap(fn ($s) => $s['items'])->keyBy('name');

    // sell_always sin stock: se muestra.
    expect($items->has('Cerveza'))->toBeTrue();
    // hide sin stock: NO se muestra.
    expect($items->has('Vino'))->toBeFalse();
    // mark_out_of_stock sin stock: se muestra marcado agotado.
    expect($items->get('Mezcal')['out_of_stock'])->toBeTrue();
    // precio de canal gana sobre el base.
    expect($items->get('Refresco')['price'])->toBe('25.00');
});

it('un slug inexistente, tienda apagada o módulo apagado dan 404', function () {
    $this->get('/t/no-existe')->assertNotFound();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => Store::query()->update(['is_active' => false]));
    $this->get('/t/fonda-tienda')->assertNotFound();

    app(TenantContext::class)->runFor($this->tenant->id, function () {
        Store::query()->update(['is_active' => true]);
        app(ManageTenantModules::class)->set('Ecommerce', false);
    });
    $this->get('/t/fonda-tienda')->assertNotFound();
});

it('agrega al carrito y calcula el total', function () {
    $cart = $this->postJson('/t/fonda-tienda/cart', [
        'article_ulid' => $this->siempre->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 2,
    ])->assertStatus(201)->json('data');

    expect($cart['count'])->toBe(2);
    expect($cart['total'])->toBe('120.00'); // 60 x 2

    // El precio de canal se respeta en el carrito.
    $cart = $this->postJson('/t/fonda-tienda/cart', [
        'article_ulid' => $this->canal->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1,
    ])->assertStatus(201)->json('data');

    expect($cart['total'])->toBe('145.00'); // 120 + 25
});

it('no deja agregar un artículo agotado', function () {
    // mark_out_of_stock sin existencia: rechazado.
    $this->postJson('/t/fonda-tienda/cart', [
        'article_ulid' => $this->agotado->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1,
    ])->assertStatus(422);
});

it('un artículo mark_out_of_stock CON existencia sí se agrega', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function () {
        $wh = Warehouse::factory()->create(['branch_id' => $this->branch->id]);
        ArticleStock::create(['warehouse_id' => $wh->id, 'article_id' => $this->agotado->id, 'quantity' => '3.0000']);
    });

    $this->postJson('/t/fonda-tienda/cart', [
        'article_ulid' => $this->agotado->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1,
    ])->assertStatus(201);
});

it('actualiza cantidades y quita líneas', function () {
    $this->postJson('/t/fonda-tienda/cart', ['article_ulid' => $this->siempre->ulid, 'branch_ulid' => $this->branch->ulid, 'quantity' => 1])->assertStatus(201);

    $cart = $this->patchJson('/t/fonda-tienda/cart', ['article_ulid' => $this->siempre->ulid, 'quantity' => 5])->assertOk()->json('data');
    expect($cart['count'])->toBe(5);

    $cart = $this->deleteJson("/t/fonda-tienda/cart/{$this->siempre->ulid}")->assertOk()->json('data');
    expect($cart['items'])->toBe([]);
});

it('rechaza una sucursal que la tienda no atiende', function () {
    $ajena = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => \App\Modules\Organization\Infrastructure\Models\Branch::factory()->create(),
    );

    $this->postJson('/t/fonda-tienda/cart', [
        'article_ulid' => $this->siempre->ulid, 'branch_ulid' => $ajena->ulid, 'quantity' => 1,
    ])->assertStatus(422);
});

it('el slug de una tienda no expone artículos de otro negocio', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->runFor($otro['tenant']->id, function () use ($otro): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);
        $cat = ArticleCategory::create(['name' => 'Panadería', 'level' => 1]);
        $art = Article::create(['name' => 'Croissant secreto', 'category_id' => $cat->id, 'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id, 'is_sellable' => true, 'base_price' => '40.00', 'is_available_in_pos' => true]);
        ArticleStoreSetting::create(['article_id' => $art->id, 'is_in_store' => true, 'stock_policy' => 'sell_always']);
        $s = Store::create(['slug' => 'cafe-tienda', 'name' => 'Café en línea', 'is_active' => true]);
        $s->storeBranches()->create(['branch_id' => $otro['branch']->id]);
    });
    app(TenantContext::class)->forget();

    $catalog = $this->getJson('/t/fonda-tienda/catalog')->assertOk()->json('data.catalog');
    $names = collect($catalog)->flatMap(fn ($s) => $s['items'])->pluck('name');

    expect($names)->toContain('Cerveza');
    expect($names)->not->toContain('Croissant secreto');
});

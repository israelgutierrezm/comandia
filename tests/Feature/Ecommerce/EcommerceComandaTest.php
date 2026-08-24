<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use App\Modules\Shared\Domain\Events\Broadcast\AreaOrderCommanded;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Event;

/**
 * COMANDAS DE E-COMMERCE (Iteración 8, Tanda D parte 2)
 *
 * Aceptar un pedido genera sus comandas por área, reusando la infraestructura del POS por eventos del kernel: `Printing`
 * imprime la comanda por la impresora del área (mismo contrato de payload que el mostrador) y `Pos` la manda a la pantalla
 * de cocina por el mismo canal de área. La cocina trata igual al mostrador y a la tienda. Ni `Printing` ni `Pos` nombran a
 * `Ecommerce`: reaccionan a `EcommerceOrderAccepted`.
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

        $warehouse = Warehouse::factory()->create(['branch_id' => $this->branch->id]);

        $this->printer = Printer::create([
            'branch_id' => $this->branch->id, 'code' => 'COCINA', 'name' => 'Impresora de cocina',
            'connection' => PrinterConnection::Network, 'target' => '192.168.1.50:9100', 'paper_width' => 80, 'supports_cash_drawer' => false,
        ]);
        $this->area = PreparationArea::create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $warehouse->id, 'printer_id' => $this->printer->id, 'code' => 'COC', 'name' => 'Cocina',
        ]);

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

        $store = Store::create(['slug' => 'fonda-tienda', 'name' => 'Fonda', 'is_active' => true]);
        $store->storeBranches()->create(['branch_id' => $this->branch->id]);
        PaymentGatewaySetting::create(['active_gateway' => 'fake']);
        $this->customer = Customer::create(['name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga']);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/** Coloca un pedido de 2 enchiladas y lo paga; devuelve el ULID. Repone el guard `web` para el personal que sigue. */
function placePaidComandaOrder(): string
{
    test()->actingAs(test()->customer, 'customer');
    test()->postJson('/t/fonda-tienda/cart', ['article_ulid' => test()->article->ulid, 'branch_ulid' => test()->branch->ulid, 'quantity' => 2])->assertStatus(201);
    $ulid = test()->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertStatus(201)->json('data.ulid');
    test()->postJson('/t/fonda-tienda/webhook/fake', ['reference' => $ulid, 'approved' => 1, 'amount' => '200.00'])->assertOk();
    auth()->shouldUse('web');

    return $ulid;
}

it('aceptar imprime una comanda por área con las líneas del pedido', function () {
    $ulid = placePaidComandaOrder();

    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    $job = PrintJob::query()->where('printer_id', $this->printer->id)->latest('id')->first();

    expect($job)->not->toBeNull();
    expect($job->pos_ticket_id)->toBeNull();               // no pasó por un ticket del POS
    expect($job->payload['kind'])->toBe('command');        // mismo contrato que la comanda del mostrador
    expect($job->payload['area'])->toBe('Cocina');
    expect($job->payload['items'][0]['name'])->toBe('Enchiladas');
    expect((string) $job->payload['items'][0]['quantity'])->toBe('2');
});

it('aceptar manda la comanda a la pantalla de cocina del área', function () {
    Event::fake([AreaOrderCommanded::class]);

    $ulid = placePaidComandaOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();

    Event::assertDispatched(AreaOrderCommanded::class, fn (AreaOrderCommanded $e): bool => $e->areaUlid === $this->area->ulid
        && count($e->lines) === 1
        && $e->lines[0]['name'] === 'Enchiladas'
        && $e->lines[0]['quantity'] === '2');
});

it('un área sin impresora no imprime, pero la comanda igual llega a la pantalla', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->area->update(['printer_id' => null]));
    Event::fake([AreaOrderCommanded::class]);

    $ulid = placePaidComandaOrder();
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/orders/{$ulid}/accept")->assertOk();

    // Sin impresora no se encola nada (como el POS, §6.2)…
    app(TenantContext::class)->set($this->tenant->id);
    expect(PrintJob::query()->count())->toBe(0);

    // …pero la pantalla de cocina sí lo recibe: es el respaldo cuando no hay papel.
    Event::assertDispatched(AreaOrderCommanded::class);
});

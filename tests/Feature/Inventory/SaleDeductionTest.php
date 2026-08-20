<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Jobs\DeductSoldItems;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Queue;

/**
 * EL DESCUENTO DE INVENTARIO POR VENTA (§6.2, paso 15)
 *
 * ## El único camino asíncrono, y por qué no es una optimización
 *
 * §6.2 dice que el POS nunca se bloquea por inventario. No es por velocidad: un platillo con receta de tres niveles
 * puede tocar veinte artículos, y cualquiera puede tener una receta mal capturada. Si eso corriera dentro del cobro, un
 * error de receta impediría cobrar — alguien con el cambio en la mano y una pantalla que dice que no se pudo.
 *
 * La contrapartida está aceptada desde §6.2: existencias negativas permitidas, y unos segundos de atraso.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $pieza = Unit::query()->where('code', 'pza')->sole();
    $gramo = Unit::query()->where('code', 'g')->sole();

    $this->almacenSucursal = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    // Un almacén propio de la cocina: es lo que hace que un conteo por área tenga sentido.
    $this->almacenCocina = Warehouse::create([
        'branch_id' => $this->branch->id,
        'kind' => 'branch',
        'code' => 'ALM-COCINA',
        'name' => 'Almacén de cocina',
    ]);

    $this->cocina = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->almacenCocina->id,
        'code' => 'COCINA',
        'name' => 'Cocina',
    ]);

    $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);
    $bebidas = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    // Un INSUMO: se consume por receta.
    $this->carne = Article::create([
        'name' => 'Carne molida',
        'category_id' => $alimentos->id,
        'base_unit_id' => $gramo->id,
        'is_inventoriable' => true,
        'is_supply' => true,
    ]);

    // Un PRODUCIBLE vendible: explota su receta.
    $this->hamburguesa = Article::create([
        'name' => 'Hamburguesa',
        'category_id' => $alimentos->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'is_producible' => true,
        'base_price' => '120.00',
        'is_available_in_pos' => true,
    ]);

    app(SaveRecipe::class)->save($this->hamburguesa, [[
        'component_article_id' => $this->carne->id,
        'quantity' => '150.0000',
        'unit_id' => $gramo->id,
    ]], outputQuantity: '1');

    // Un INVENTARIABLE vendible que no es producible: se consume él mismo.
    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $bebidas->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'is_inventoriable' => true,
        'base_price' => '50.00',
        'is_available_in_pos' => true,
    ]);

    // Un vendible que NO controla existencias: un servicio.
    $this->servicio = Article::create([
        'name' => 'Servicio de mesero',
        'category_id' => $bebidas->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'base_price' => '30.00',
        'is_available_in_pos' => true,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $alimentos->id,
        'preparation_area_id' => $this->cocina->id,
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);

    app(TenantContext::class)->forget();

    /** Abre caja, vende lo indicado y cobra. Devuelve el ULID de la cuenta. */
    $this->venderYCobrar = function (array $lineas, string $total): string {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', [
                'terminal_ulid' => $this->terminal->ulid,
                'opening_float' => '500.00',
            ]);

        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra 1'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", ['lines' => $lineas])
            ->assertCreated();

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
                'payments' => [['payment_method_ulid' => $this->efectivo->ulid, 'amount' => $total]],
            ])
            ->assertOk();

        return $cuenta;
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Que sea asíncrono
// ---------------------------------------------------------------------------

it('cobrar ENCOLA el descuento y no lo hace en la petición', function () {
    Queue::fake();

    ($this->venderYCobrar)(
        [['article_ulid' => $this->cerveza->ulid, 'quantity' => '2']],
        '100.00',
    );

    Queue::assertPushed(DeductSoldItems::class);

    app(TenantContext::class)->set($this->tenant->id);

    // Nada en el kardex todavía: el trabajo está en la cola. Es lo que hace que un error de receta no pueda impedir un
    // cobro.
    expect(StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->count())->toBe(0);
});

it('una cuenta sin items vendibles NO encola nada', function () {
    // Un job vacío en la cola es ruido que alguien acabará investigando.
    Queue::fake();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ]);

    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Vacía'])
        ->json('data.ulid');

    // Una cuenta en cero se cobra con un pago de cero… que no se admite. Se cancela, que es lo que pasa de verdad.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", ['reason' => 'Nadie pidió nada'])
        ->assertOk();

    Queue::assertNotPushed(DeductSoldItems::class);
});

// ---------------------------------------------------------------------------
// Qué se consume
// ---------------------------------------------------------------------------

it('un PRODUCIBLE explota su receta', function () {
    $cuenta = ($this->venderYCobrar)(
        [['article_ulid' => $this->hamburguesa->ulid, 'quantity' => '2']],
        '240.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    // Dos hamburguesas de 150 g de carne cada una.
    $movimiento = StockMovement::query()
        ->where('kind', StockMovementKind::SaleConsumption->value)
        ->sole();

    expect((int) $movimiento->article_id)->toBe($this->carne->id);
    expect((string) $movimiento->quantity)->toBe('300.0000');

    // Y del almacén de la COCINA, que es el área que lo preparó. Sin esto, todo saldría de un almacén único y el
    // conteo de la barra nunca cuadraría.
    expect((int) $movimiento->warehouse_id)->toBe($this->almacenCocina->id);
});

it('un INVENTARIABLE no producible se consume a sí mismo, del almacén de la sucursal', function () {
    // Una cerveza que el mesero saca de la nevera: no tiene área, así que descuenta del almacén de la sucursal.
    ($this->venderYCobrar)(
        [['article_ulid' => $this->cerveza->ulid, 'quantity' => '3']],
        '150.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    $movimiento = StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->sole();

    expect((int) $movimiento->article_id)->toBe($this->cerveza->id);
    expect((string) $movimiento->quantity)->toBe('3.0000');
    expect((int) $movimiento->warehouse_id)->toBe($this->almacenSucursal->id);
});

it('un artículo que NO controla existencias no descuenta nada', function () {
    // Un servicio, una comisión. Es legítimo y no un error.
    ($this->venderYCobrar)(
        [['article_ulid' => $this->servicio->ulid, 'quantity' => '1']],
        '30.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    expect(StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->count())->toBe(0);
});

it('las EXISTENCIAS NEGATIVAS están permitidas', function () {
    // §6.2 lo dice: el POS nunca se bloquea por inventario. No hay existencias de carne y la venta se descuenta igual,
    // dejando el saldo en negativo — que es información honesta, no un error.
    ($this->venderYCobrar)(
        [['article_ulid' => $this->hamburguesa->ulid, 'quantity' => '1']],
        '120.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    $movimiento = StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->sole();

    expect((string) $movimiento->balance_after)->toBe('-150.0000');
});

// ---------------------------------------------------------------------------
// Idempotencia — el mecanismo de reparación
// ---------------------------------------------------------------------------

it('re-despachar el job NO duplica el descuento', function () {
    // El mecanismo de reparación de este sistema ES re-despachar. Sin idempotencia, reparar duplicaría lo que ya se
    // había escrito.
    $cuenta = ($this->venderYCobrar)(
        [
            ['article_ulid' => $this->hamburguesa->ulid, 'quantity' => '1'],
            ['article_ulid' => $this->cerveza->ulid, 'quantity' => '2'],
        ],
        '220.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    $antes = StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->get();
    expect($antes)->toHaveCount(2);

    $items = $antes->map(fn (StockMovement $m): array => [
        'item_ulid' => 'x',
        'article_id' => (int) $m->article_id,
        'quantity' => '1',
        'preparation_area_id' => null,
        'is_courtesy' => false,
    ])->all();

    app(TenantContext::class)->forget();

    // Se vuelve a correr el job REAL con los mismos items de la cuenta.
    $original = app(\App\Modules\Pos\Infrastructure\Models\PosAccount::class);

    app(TenantContext::class)->set($this->tenant->id);

    $vendidos = \App\Modules\Pos\Infrastructure\Models\PosOrderItem::query()
        ->whereHas('account', fn ($q) => $q->where('ulid', $cuenta))
        ->get()
        ->map(fn ($i): array => [
            'item_ulid' => (string) $i->ulid,
            'article_id' => (int) $i->article_id,
            'quantity' => (string) $i->quantity,
            'preparation_area_id' => $i->preparation_area_id === null ? null : (int) $i->preparation_area_id,
            'is_courtesy' => false,
        ])->values()->all();

    app(TenantContext::class)->forget();

    dispatch_sync(new DeductSoldItems($this->tenant->id, (int) $this->branch->id, $cuenta, $vendidos));

    app(TenantContext::class)->set($this->tenant->id);

    expect(StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Que nada tumbe el cobro
// ---------------------------------------------------------------------------

it('un item con receta ROTA no impide descontar los demás', function () {
    // Un platillo mal capturado no puede impedir que se descuenten los otros diecinueve. Y sobre todo: la cuenta ya
    // está pagada, así que nada de esto puede volver hacia el cobro.
    app(TenantContext::class)->set($this->tenant->id);

    // Un producible SIN receta activa: `ResolveProductionConsumption` lanza.
    $rota = Article::create([
        'name' => 'Platillo sin receta',
        'category_id' => ArticleCategory::query()->where('name', 'Alimentos')->sole()->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'is_producible' => true,
        'base_price' => '90.00',
        'is_available_in_pos' => true,
    ]);

    app(TenantContext::class)->forget();

    ($this->venderYCobrar)(
        [
            ['article_ulid' => $rota->ulid, 'quantity' => '1'],
            ['article_ulid' => $this->cerveza->ulid, 'quantity' => '1'],
        ],
        '140.00',
    );

    app(TenantContext::class)->set($this->tenant->id);

    // La cerveza sí se descontó.
    $movimientos = StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->get();

    expect($movimientos)->toHaveCount(1);
    expect((int) $movimientos->first()->article_id)->toBe($this->cerveza->id);
});

it('los movimientos de un negocio son invisibles para otro', function () {
    ($this->venderYCobrar)(
        [['article_ulid' => $this->cerveza->ulid, 'quantity' => '1']],
        '50.00',
    );

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    app(TenantContext::class)->set($otro['tenant']->id);

    expect(StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->count())->toBe(0);
});

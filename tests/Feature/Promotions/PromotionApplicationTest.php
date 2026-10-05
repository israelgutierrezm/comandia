<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Promotions\Infrastructure\Models\PromotionApplication;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LA APLICACIÓN DE PROMOCIONES AL COBRAR (Iteración 6, §6.3, D310, D315)
 *
 * ## Lo que estas pruebas fijan
 *
 * Que el motor decide y el POS materializa el efecto en `pos_discounts` **al cobrar**, reduciendo el total; que cada
 * tipo calcula su monto en el SERVIDOR; que «mejor gana»; que una promoción fuera de vigencia no aplica; que el asiento
 * del diario usa el tipo `Promotion` (separado del descuento manual); y que queda el registro por venta.
 *
 * El monto lo calcula siempre el servidor: el cliente nunca manda el descuento de una promoción.
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
    $this->membershipId = $alta['membership']->id;

    app(TenantContext::class)->set($this->tenant->id);

    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $this->categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $this->categoria->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);

    // Una promoción, creada por modelo para ir al grano del motor.
    $this->promocion = function (array $attrs, ?int $categoryId = null, ?int $articleId = null): Promotion {
        return app(TenantContext::class)->runFor($this->tenant->id, function () use ($attrs, $categoryId, $articleId): Promotion {
            $promo = Promotion::create([
                'name' => $attrs['name'] ?? 'Promo',
                'created_by_membership_id' => $this->membershipId,
                ...$attrs,
            ]);
            $promo->targets()->create([
                'article_category_id' => $categoryId,
                'article_id' => $articleId,
            ]);

            return $promo;
        });
    };

    app(TenantContext::class)->forget();

    /** Abre caja, abre cuenta de barra, captura y devuelve el ULID de la cuenta. */
    $this->cuentaCon = function (string $articleUlid, string $qty): string {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $this->terminal->ulid, 'opening_float' => '0.00'])
            ->assertCreated();

        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $articleUlid, 'quantity' => $qty]],
            ])
            ->assertCreated();

        return $cuenta;
    };

    /** Cobra en efectivo el monto indicado. */
    $this->cobrar = fn (string $cuenta, string $monto) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => ($this->efectivoUlid)(), 'amount' => $monto, 'tendered_amount' => $monto]],
        ]);

    $this->efectivoUlid = fn (): string => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => \App\Modules\Finance\Infrastructure\Models\PaymentMethod::query()->where('code', 'CASH')->sole()->ulid,
    );
});

afterEach(fn () => app(TenantContext::class)->forget());

it('un porcentaje por categoría se aplica al cobrar', function () {
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    // 100 − 10% = 90.
    $respuesta = ($this->cobrar)($cuenta, '90.00')->assertOk();

    $respuesta->assertJsonPath('data.status', 'paid');
    $respuesta->assertJsonPath('data.totals.total', '90.00');
    $respuesta->assertJsonPath('data.totals.discount_total', '10.00');

    app(TenantContext::class)->set($this->tenant->id);

    // El efecto quedó en pos_discounts, con origen promoción y sin autorizador.
    $discount = PosDiscount::query()->fromPromotion()->sole();
    expect((string) $discount->resulting_amount)->toBe('10.00');
    expect($discount->authorized_by_membership_id)->toBeNull();

    // El registro por venta.
    $application = PromotionApplication::query()->sole();
    expect((string) $application->amount_discounted)->toBe('10.00');

    // El asiento del diario, con tipo Promotion (no Discount) y en negativo.
    $movement = FinancialMovement::query()->where('type', FinancialMovementType::Promotion->value)->sole();
    expect((string) $movement->amount)->toBe('-10.00');
});

it('un 2x1 regala la unidad más barata', function () {
    ($this->promocion)(['name' => '2x1 cervezas', 'type' => 'nxm', 'buy_quantity' => 2, 'pay_quantity' => 1], articleId: $this->cerveza->id);

    // Dos cervezas de 100: se regala una.
    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '2');

    ($this->cobrar)($cuenta, '100.00')->assertOk()
        ->assertJsonPath('data.totals.total', '100.00')
        ->assertJsonPath('data.totals.discount_total', '100.00');
});

it('cuando dos promociones aplican, gana la que más descuenta', function () {
    // 10% de 100 = 10; monto fijo de 25. Gana el de 25.
    ($this->promocion)(['name' => '10%', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);
    ($this->promocion)(['name' => '25 fijo', 'type' => 'amount', 'amount_value' => '25.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    ($this->cobrar)($cuenta, '75.00')->assertOk()
        ->assertJsonPath('data.totals.total', '75.00');

    app(TenantContext::class)->set($this->tenant->id);

    // UNA sola promoción aplicada, no las dos: no se acumulan por omisión.
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(1);
    expect((string) PosDiscount::query()->fromPromotion()->sole()->resulting_amount)->toBe('25.00');
});

it('una promoción fuera de vigencia no aplica', function () {
    ($this->promocion)([
        'name' => 'Futura',
        'type' => 'percentage',
        'percent_value' => '50.00',
        'starts_on' => now()->addWeek()->toDateString(),
    ], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    // Sin promoción vigente: se cobra el precio entero.
    ($this->cobrar)($cuenta, '100.00')->assertOk()
        ->assertJsonPath('data.totals.total', '100.00')
        ->assertJsonPath('data.totals.discount_total', '0.00');

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(0);
});

it('sin promociones, el cobro funciona igual (null-object)', function () {
    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    ($this->cobrar)($cuenta, '100.00')->assertOk()
        ->assertJsonPath('data.totals.total', '100.00');
});

it('la vista previa muestra el descuento sin escribir nada', function () {
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}/promotions-preview")
        ->assertOk();

    // El servidor calculó el monto y el total; el cliente sólo lo pinta.
    $respuesta->assertJsonPath('data.total', '10.00');
    $respuesta->assertJsonPath('data.applied.0.name', '10% bebidas');
    $respuesta->assertJsonPath('data.applied.0.amount', '10.00');

    // Y no escribió nada: la vista previa es pura. El efecto se graba al cobrar, no al previsualizar.
    app(TenantContext::class)->set($this->tenant->id);
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(0);
});

it('tras cobrar, la vista previa vuelve vacía', function () {
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');
    ($this->cobrar)($cuenta, '90.00')->assertOk();

    // Ya materializada: la promoción vive en el total, no hay nada que anticipar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}/promotions-preview")
        ->assertOk()
        ->assertJsonPath('data.total', '0.00')
        ->assertJsonCount(0, 'data.applied');
});

// ---------------------------------------------------------------------------
// Categorías con subcategorías (D371)
// ---------------------------------------------------------------------------

it('una promoción por categoría alcanza a sus subcategorías', function () {
    // «10 % en Bebidas» tiene que alcanzar a una cerveza clasificada en «Bebidas › Cervezas». Antes el motor comparaba
    // sólo la categoría directa del artículo, y la pantalla ofrecía las raíces: la promoción no alcanzaba a nada.
    $artesanal = app(TenantContext::class)->runFor($this->tenant->id, function (): Article {
        $cervezas = ArticleCategory::create(['name' => 'Cervezas', 'parent_id' => $this->categoria->id, 'level' => 2]);

        return Article::create([
            'name' => 'Cerveza artesanal',
            'category_id' => $cervezas->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true,
            'base_price' => '100.00',
            'is_available_in_pos' => true,
        ]);
    });

    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($artesanal->ulid, '1');

    ($this->cobrar)($cuenta, '90.00')->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.totals.total', '90.00');
});

it('una promoción por SUBCATEGORÍA no alcanza a su categoría padre', function () {
    // La inclusión va hacia abajo: «Bebidas › Cervezas» no descuenta lo que vive directamente en «Bebidas».
    $cervezas = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => ArticleCategory::create(['name' => 'Cervezas', 'parent_id' => $this->categoria->id, 'level' => 2]),
    );

    ($this->promocion)(['name' => '10% cervezas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $cervezas->id);

    // `$this->cerveza` está en la raíz «Bebidas».
    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    ($this->cobrar)($cuenta, '100.00')->assertOk()
        ->assertJsonPath('data.totals.total', '100.00')
        ->assertJsonPath('data.totals.discount_total', '0.00');
});

// ---------------------------------------------------------------------------
// Dividir una cuenta con promoción (D366)
// ---------------------------------------------------------------------------

it('dividir aplica la promoción ANTES de repartir: cada parte ya lleva su descuento', function () {
    // La promoción se materializaba al cobrar, y la madre de una división no se cobra: se cobran sus partes, que no tienen
    // líneas. Dividir le quitaba la promoción a la cuenta.
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '2');

    $partes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])
        ->assertOk()
        ->json('data');

    // 200 − 10 % = 180, en dos partes de 90.
    expect(collect($partes)->pluck('totals.total')->all())->toBe(['90.00', '90.00']);

    // La madre ya no anuncia la promoción: vive en sus partes.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}/promotions-preview")
        ->assertOk()
        ->assertJsonCount(0, 'data.applied');

    ($this->cobrar)($partes[0]['ulid'], '90.00')->assertOk();
    ($this->cobrar)($partes[1]['ulid'], '90.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertJsonPath('data.status', 'paid');

    app(TenantContext::class)->set($this->tenant->id);

    // Una sola vez, en la línea de la madre: cobrar las partes no la vuelve a aplicar.
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(1);
    expect((string) PosDiscount::query()->fromPromotion()->sole()->resulting_amount)->toBe('20.00');
    expect(PromotionApplication::query()->count())->toBe(1);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Promotion->value)->sole();
    expect((string) $asiento->amount)->toBe('-20.00');
});

it('dividir una cuenta con promoción exige la caja abierta, y sin promoción no', function () {
    // El descuento de una promoción pertenece al turno en que se aplica, como un descuento manual.
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $abrirCuenta = function (string $articleUlid): string {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $articleUlid, 'quantity' => '2']],
            ])
            ->assertCreated();

        return $cuenta;
    };

    // Sin caja: con promoción, 409 con el porqué; nada se reparte ni se materializa.
    $conPromo = $abrirCuenta($this->cerveza->ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$conPromo}/split", ['parts' => 2])
        ->assertStatus(409)
        ->assertJsonPath('title', fn (string $titulo): bool => str_contains($titulo, 'abrir la caja'));

    // Un artículo sin promoción, en otra categoría: se divide sin caja, como siempre.
    $cafe = app(TenantContext::class)->runFor($this->tenant->id, fn () => Article::create([
        'name' => 'Café',
        'category_id' => ArticleCategory::create(['name' => 'Cafetería', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '40.00',
        'is_available_in_pos' => true,
    ]));

    $sinPromo = $abrirCuenta($cafe->ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$sinPromo}/split", ['parts' => 2])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(0);
});

it('una división deshecha conserva su promoción y lo que se capture después se evalúa al cobrar', function () {
    // Deshacer la división devuelve una cuenta normal con la promoción ya grabada en su línea. La idempotencia por CUENTA
    // habría dejado sin promoción todo lo capturado después; por LÍNEA, cada línea se evalúa una vez.
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    $partes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])
        ->assertOk()
        ->json('data');

    foreach ($partes as $parte) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$parte['ulid']}/cancel", ['reason' => 'Mejor pagan junto'])
            ->assertOk();
    }

    $conPromo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.items.0.ulid');

    // La línea con promoción tiene la cantidad FIJA: su descuento se calculó para una cerveza.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/{$conPromo}/quantity", ['quantity' => '3'])
        ->assertStatus(409);

    // Y otra cerveza no se le suma: abre su propia línea, que se evalúa al cobrar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '1']],
        ])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertJsonCount(2, 'data.items');

    // Dos cervezas de 100 con 10 % cada una: 180.
    ($this->cobrar)($cuenta, '180.00')->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.totals.total', '180.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(PosDiscount::query()->fromPromotion()->pluck('resulting_amount')->map(fn ($monto): string => (string) $monto)->all())
        ->toBe(['10.00', '10.00']);
});

it('quitar una línea que ya lleva promoción la CANCELA en vez de borrarla', function () {
    // El descuento ya se asentó en el diario y apunta a su línea: borrarla lo dejaría sin origen (la base lo impide, y
    // eso era un 500). La línea queda cancelada, sin PIN —nadie la preparó— y fuera del total.
    ($this->promocion)(['name' => '10% bebidas', 'type' => 'percentage', 'percent_value' => '10.00'], categoryId: $this->categoria->id);

    $cuenta = ($this->cuentaCon)($this->cerveza->ulid, '1');

    $partes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])
        ->assertOk()
        ->json('data');

    foreach ($partes as $parte) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$parte['ulid']}/cancel", ['reason' => 'Se equivocaron'])
            ->assertOk();
    }

    $linea = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.items.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", ['item_ulids' => [$linea]])
        ->assertOk()
        ->assertJsonPath('data.totals.total', '0.00');

    app(TenantContext::class)->set($this->tenant->id);

    $item = PosOrderItem::query()->where('ulid', $linea)->sole();
    expect($item->status->value)->toBe('cancelled');
    expect($item->cancellation_destination)->toBe('none');
    expect(PosDiscount::query()->fromPromotion()->count())->toBe(1);
});

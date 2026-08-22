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

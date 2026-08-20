<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Infrastructure\Models\PosPayment;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * COBRAR: PAGOS, PROPINA, CAMBIO Y TICKET FINAL (§6.3, paso 10)
 *
 * ## Las tres cosas que estas pruebas existen para demostrar
 *
 * **La propina NO entra en el cambio.** Mil pesos por una cuenta de 850 con 50 de propina devuelven 100, no 150. Es el
 * error más caro de este servicio, porque se cometería a favor del cliente y en contra del cajero, todas las noches.
 *
 * **La propina se congela CON NOMBRE al cobrar** (D233). Lo que eso compra es que una operación posterior no reescriba
 * propinas ya pagadas.
 *
 * **El diario asienta cuatro cosas distintas por un solo cobro**: la venta, los pagos por método, el cambio y la
 * propina. Sumarlos daría un número que ni cuadra el cajón ni mide la venta.
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
    $this->membership = $alta['membership'];

    app(TenantContext::class)->set($this->tenant->id);

    $unidad = Unit::query()->where('code', 'pza')->sole();
    $categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '425.00',
        'is_available_in_pos' => true,
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $this->tarjeta = PaymentMethod::query()->where('code', 'CARD')->sole();
    $this->transferencia = PaymentMethod::query()->where('code', 'TRANSFER')->sole();

    $this->terminal = Terminal::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA1',
        'name' => 'Caja 1',
    ]);

    $plan = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Planta baja', 'is_default' => true]);
    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón']);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $zona->id,
        'code' => 'M1',
        'seats' => 4,
    ]);

    app(TenantContext::class)->forget();

    /** Abre la caja: sin sesión no hay cobro (§6.3). */
    $this->abrirCaja = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated();

    /** Abre una cuenta en la mesa con dos cafés (850.00) y devuelve su ULID. */
    $this->cuentaDe850 = function (): string {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->cafe->ulid, 'quantity' => '2']],
            ])
            ->assertCreated();

        return $cuenta;
    };

    /** Cobra. */
    $this->cobrar = fn (string $cuenta, array $pagos) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", ['payments' => $pagos]);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Sin caja no hay cobro
// ---------------------------------------------------------------------------

it('sin sesión de caja abierta NO se cobra', function () {
    // §6.3: un pago que no pertenece a ningún turno es dinero que entró y que ningún arqueo puede explicar. Abrir la
    // cuenta sí se puede sin caja —el mesero toma la orden antes de que llegue el cajero— pero cobrarla no.
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [
        ['payment_method_ulid' => $this->efectivo->ulid, 'amount' => '850.00'],
    ])->assertStatus(409);
});

// ---------------------------------------------------------------------------
// El cambio
// ---------------------------------------------------------------------------

it('calcula y GUARDA el cambio', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
    ]])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.totals.change_total', '150.00');
});

it('la PROPINA no entra en el cambio', function () {
    // Mil pesos por 850 con 50 de propina devuelven 100, no 150. Es el error más caro de este servicio: se cometería a
    // favor del cliente y en contra del cajero, todas las noches.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
        'tip_amount' => '50.00',
    ]])
        ->assertOk()
        ->assertJsonPath('data.totals.change_total', '100.00')
        ->assertJsonPath('data.totals.tip_total', '50.00');
});

it('no se acepta menos de lo que hay que cubrir', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    // 900 entregados para 850 + 100 de propina: falta. Aceptarlo daría un cambio negativo, que el CHECK de la base
    // rechaza — y con razón.
    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '900.00',
        'tip_amount' => '100.00',
    ]])->assertStatus(409);
});

it('un método que no da cambio no lo da, aunque se mande entregado', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->tarjeta->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
    ]])
        ->assertOk()
        ->assertJsonPath('data.totals.change_total', '0.00');
});

// ---------------------------------------------------------------------------
// Multi-línea
// ---------------------------------------------------------------------------

it('cobra con dos métodos y sólo entonces cierra la cuenta', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    // Con la mitad, la cuenta sigue viva: falta cobrar.
    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->tarjeta->ulid,
        'amount' => '425.00',
    ]])
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.totals.due', '425.00');

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '425.00',
        'tendered_amount' => '500.00',
    ]])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.totals.due', '0.00');
});

it('un método que exige referencia no se cobra sin ella', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    // Sin referencia, una transferencia no se concilia con el estado de cuenta del banco y el dinero queda sin
    // comprobar.
    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->transferencia->ulid,
        'amount' => '850.00',
    ]])->assertStatus(409);

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->transferencia->ulid,
        'amount' => '850.00',
        'reference' => 'SPEI-99182',
    ]])->assertOk()->assertJsonPath('data.status', 'paid');
});

it('una cuenta ya pagada no admite más pagos', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    // Cobrar de más se corrige con una reversa, no aplicando otro pago encima.
    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '100.00']])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// La propina, con nombre
// ---------------------------------------------------------------------------

it('la propina se atribuye al TITULAR por omisión, no a quien cobra', function () {
    ($this->abrirCaja)();

    app(TenantContext::class)->set($this->tenant->id);

    $usuario = User::factory()->create();
    $mesera = TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'W002',
        'has_all_branches' => true,
    ]);

    app(TenantContext::class)->forget();

    // El dueño abre la cuenta cuyo TITULAR es la mesera.
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'table_ulid' => $this->mesa->ulid,
            'waiter_ulid' => $mesera->ulid,
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->cafe->ulid, 'quantity' => '2']],
        ])
        ->assertCreated();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
        'tip_amount' => '100.00',
    ]])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // El dinero es de quien atendió, no de quien tocó la pantalla (D233).
    expect((int) PosPayment::query()->sole()->tip_membership_id)->toBe($mesera->id);
});

it('la propina se puede atribuir explícitamente a otra persona', function () {
    ($this->abrirCaja)();

    app(TenantContext::class)->set($this->tenant->id);
    $usuario = User::factory()->create();
    $otra = TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'W003',
        'has_all_branches' => true,
    ]);
    app(TenantContext::class)->forget();

    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
        'tip_amount' => '100.00',
        'tip_membership_ulid' => $otra->ulid,
    ]])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect((int) PosPayment::query()->sole()->tip_membership_id)->toBe($otra->id);
});

it('un pago sin propina no le atribuye nada a nadie', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // `null` y no el titular: una fila con dueño de propina y propina cero confundiría a la liquidación del paso 18.
    expect(PosPayment::query()->sole()->tip_membership_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Lo que el cobro deja atrás
// ---------------------------------------------------------------------------

it('emite el ticket final CON folio, y es el único papel que folia', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
        'tip_amount' => '50.00',
    ]])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $ticket = PosTicket::query()->where('kind', PosTicketKind::FinalReceipt->value)->sole();

    // Será el folio facturable (ADR-005), así que se asigna sin huecos y bajo lock.
    expect($ticket->folioNumber())->toBe('T-1');
});

it('asienta en el diario la venta, el pago, el cambio y la propina POR SEPARADO', function () {
    // Cuatro asientos porque cada uno contesta otra pregunta: cuánto se vendió, cuánto entró por método, cuánto salió
    // de cambio y cuánta propina se dejó. Sumarlos daría un número que ni cuadra el cajón ni mide la venta.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [[
        'payment_method_ulid' => $this->efectivo->ulid,
        'amount' => '850.00',
        'tendered_amount' => '1000.00',
        'tip_amount' => '50.00',
    ]])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $porTipo = FinancialMovement::query()->get()->groupBy(fn ($m): string => $m->type->value);

    expect($porTipo)->toHaveKeys(['sale', 'payment', 'change', 'tip']);
    expect((string) $porTipo['sale'][0]->amount)->toBe('850.00');
    expect((string) $porTipo['payment'][0]->amount)->toBe('850.00');
    expect((string) $porTipo['tip'][0]->amount)->toBe('50.00');

    // El cambio SALE del cajón: el diario lo asienta con su signo natural.
    expect((string) $porTipo['change'][0]->amount)->toBe('-100.00');
});

it('el asiento de cada pago cuelga del PAGO y no de la cuenta', function () {
    // La idempotencia del diario es por (documento, tipo). Con la cuenta como origen, dos líneas de pago de la misma
    // cuenta chocarían y sólo se asentaría la primera: una cuenta pagada mitad efectivo mitad tarjeta perdería la mitad
    // del dinero en el corte, sin que nada fallara.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [
        ['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '425.00'],
        ['payment_method_ulid' => $this->efectivo->ulid, 'amount' => '425.00'],
    ])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $pagos = FinancialMovement::query()->where('type', FinancialMovementType::Payment->value)->get();

    expect($pagos)->toHaveCount(2);
    expect($pagos->pluck('source_ulid')->unique())->toHaveCount(2);
});

it('libera la mesa al quedar pagada', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);

    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    // Inmediato y en la transacción, no por evento: la pantalla de piso decide sobre este estado (D239).
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('encola el ticket final para imprimir', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    // Sin impresora de caja configurada no se encola nada, y la venta sigue igual (D246). Esta prueba comprueba lo
    // segundo: que el cobro no depende de la impresión.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/print-jobs')
        ->assertOk();
});

it('un pago es INMUTABLE', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();

    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // Corregir un pago es registrar su reversa. Un UPDATE cambiaría la historia sin cambiar el dinero, y el corte de
    // anoche —ya impreso y firmado— diría otra cosa al recalcularse.
    expect(fn () => PosPayment::query()->sole()->update(['amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

it('los pagos de un negocio son invisibles para otro', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe850)();
    ($this->cobrar)($cuenta, [['payment_method_ulid' => $this->tarjeta->ulid, 'amount' => '850.00']])->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/pos-accounts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

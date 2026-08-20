<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\BankDeposit;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Finance\Infrastructure\Models\TipSettlement;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * DEPÓSITOS Y LIQUIDACIÓN DE PROPINAS (§6.5, §6.6, paso 18)
 *
 * ## Las dos cosas que faltaban para que el efectivo tenga un recorrido completo
 *
 * **El depósito cierra el retiro**: el dinero sale de la caja con un `withdrawal` y entra al banco con un `deposit`.
 * Sin la segunda mitad, un retiro de diez mil pesos es una salida declarada que no llega a ningún sitio.
 *
 * **La liquidación cierra la propina**: entra a la caja con el cobro y sale hacia el bolsillo del mesero. Sin
 * registrarla, el arqueo da corto por una cantidad que ningún movimiento explica — y como pasa todas las noches, la
 * diferencia deja de mirarse.
 *
 * ## Y el disponible se calcula DEL DIARIO
 *
 * Es donde una decisión del paso 10 termina de pagarse: los asientos de propina llevan como actor a quien se le
 * atribuye, no a quien cobró. Sin eso, este paso habría exigido leer `pos_payments` desde `Finance` — un ciclo — o un
 * tercer contrato de pregunta en el kernel.
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

    $unidad = Unit::query()->where('code', 'pza')->sole();
    $categoria = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);

    $this->comida = Article::create([
        'name' => 'Comida corrida',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);

    // La mesera que se lleva las propinas.
    $usuario = User::factory()->create();

    $this->mesera = TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'W001',
        'has_all_branches' => true,
    ]);

    app(TenantContext::class)->forget();

    $this->abrirCaja = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated();

    /** Vende y cobra dejando propina a la mesera. */
    $this->venderConPropina = function (string $total, string $propina): void {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', [
                'branch_ulid' => $this->branch->ulid,
                'label' => 'Barra 1',
                'waiter_ulid' => $this->mesera->ulid,
            ])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->comida->ulid, 'quantity' => bcdiv($total, '100', 0)]],
            ])
            ->assertCreated();

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
                'payments' => [[
                    'payment_method_ulid' => $this->efectivo->ulid,
                    'amount' => $total,
                    'tip_amount' => $propina,
                    'tendered_amount' => bcadd($total, $propina, 2),
                ]],
            ])
            ->assertOk();
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Depósitos
// ---------------------------------------------------------------------------

it('registra un depósito y lo asienta en NEGATIVO', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/bank-deposits', [
            'branch_ulid' => $this->branch->ulid,
            'amount' => '10000.00',
            'bank_name' => 'Banco del Bajío',
            'reference' => 'DEP-99182',
            'deposited_on' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'DEP-99182')
        // Fecha sin hora: un depósito se hace en un día, no en un instante.
        ->assertJsonPath('data.deposited_on', now()->toDateString());

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Deposit->value)->sole();

    // El dinero SALE del negocio hacia el banco.
    expect((string) $asiento->amount)->toBe('-10000.00');
});

it('un depósito NO exige caja abierta', function () {
    // Es la excepción deliberada: quien va al banco captura el depósito horas o días después, con el comprobante en la
    // mano. Exigir turno obligaría a capturarlo cuando todavía no hay comprobante.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/bank-deposits', [
            'branch_ulid' => $this->branch->ulid,
            'amount' => '5000.00',
            'bank_name' => 'Banco del Bajío',
            'reference' => 'DEP-1',
            'deposited_on' => now()->subDays(2)->toDateString(),
        ])
        ->assertCreated();
});

it('la referencia es obligatoria y la fecha no puede ser futura', function () {
    $base = [
        'branch_ulid' => $this->branch->ulid,
        'amount' => '5000.00',
        'bank_name' => 'Banco del Bajío',
        'deposited_on' => now()->toDateString(),
    ];

    // Sin folio no se puede buscar en el estado de cuenta, que es lo único para lo que sirve registrarlo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/bank-deposits', $base)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reference']]);

    // Un depósito que todavía no ocurrió no es un depósito.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/bank-deposits', array_merge($base, [
            'reference' => 'DEP-1',
            'deposited_on' => now()->addDay()->toDateString(),
        ]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['deposited_on']]);
});

it('un depósito es INMUTABLE', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/bank-deposits', [
            'branch_ulid' => $this->branch->ulid,
            'amount' => '5000.00',
            'bank_name' => 'Banco del Bajío',
            'reference' => 'DEP-1',
            'deposited_on' => now()->toDateString(),
        ])
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => BankDeposit::query()->sole()->update(['amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Propinas
// ---------------------------------------------------------------------------

it('el disponible se calcula del DIARIO y aparece con nombre', function () {
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');
    ($this->venderConPropina)('200.00', '30.00');

    $pendientes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/tip-settlements/pending')
        ->assertOk()
        ->json('data');

    expect($pendientes)->toHaveCount(1);
    expect($pendientes[0]['membership']['employee_code'])->toBe('W001');
    expect($pendientes[0]['available'])->toBe('80.00');
});

it('liquidar baja el disponible y SALE del cajón', function () {
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '80.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $this->mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '50.00',
        ])
        ->assertCreated()
        // Lo que le queda, para que la pantalla se actualice sin volver a pedir la lista entera.
        ->assertJsonPath('data.remaining', '30.00');

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()
        ->where('type', FinancialMovementType::TipSettlement->value)
        ->sole();

    // Sale del cajón: en negativo y afectando la caja. Sin esto, el arqueo daría corto por una cantidad que ningún
    // movimiento explica.
    expect((string) $asiento->amount)->toBe('-50.00');
    expect($asiento->affects_cash_drawer)->toBeTrue();

    // Y el actor es a QUIEN se le pagó: es lo que permite que el disponible siga saliendo del diario.
    expect((int) $asiento->actor_membership_id)->toBe($this->mesera->id);
});

it('no se liquida más de lo que se debe', function () {
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $this->mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '500.00',
        ])
        ->assertStatus(422);
});

it('quien ya está al corriente DESAPARECE de la lista', function () {
    // La pantalla es «a quién le debo», no «quién ha tenido propinas alguna vez». En un turno con quince meseros, la
    // segunda lista es inútil.
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $this->mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '50.00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.remaining', '0.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/tip-settlements/pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('sin caja abierta no se liquida', function () {
    // La propina se paga en efectivo del cajón: sin turno, el arqueo no sabría que salió.
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');

    // Se cierra la caja declarando lo que hay.
    app(TenantContext::class)->set($this->tenant->id);
    \App\Modules\Pos\Infrastructure\Models\PosSession::query()->sole()->update(['status' => 'closed']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $this->mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '50.00',
        ])
        ->assertStatus(422);
});

it('una liquidación es INMUTABLE', function () {
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $this->mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '50.00',
        ])
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    // Editarla cambiaría cuánto se le debe a una persona sin que ella se entere.
    expect(fn () => TipSettlement::query()->sole()->update(['amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

it('lista los depósitos y los filtra', function () {
    foreach (['DEP-1', 'DEP-2'] as $folio) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/bank-deposits', [
                'branch_ulid' => $this->branch->ulid,
                'amount' => '1000.00',
                'bank_name' => 'Banco del Bajío',
                'reference' => $folio,
                'deposited_on' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/bank-deposits')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/bank-deposits?search=DEP-2')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('las propinas de un negocio son invisibles para otro', function () {
    ($this->abrirCaja)();
    ($this->venderConPropina)('300.00', '50.00');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/tip-settlements/pending')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

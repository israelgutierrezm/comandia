<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL CORTE DE CAJA (§6.5, ADR-004, paso 19)
 *
 * ## El corte NO es una tabla: es una consulta
 *
 * Se calcula del diario y nunca se almacena como verdad paralela. Un total guardado se desvía —una reversa posterior, un
 * gasto capturado tarde— y entonces hay dos cifras que dicen ser el corte de la misma noche.
 *
 * ## Y el efectivo esperado es una SUMA, no la fórmula enumerada
 *
 * §6.5 lo escribía como «fondo + pagos − cambios − retiros − gastos − propinas + abonos». Ya no hace falta enumerarlo:
 * cada asiento lleva `affects_cash_drawer` copiado y el signo impuesto por su tipo, así que el esperado es la suma de lo
 * que toca el cajón. La diferencia práctica es que **un tipo nuevo entra solo** — la fórmula enumerada habría que
 * editarla, y el día que alguien olvidara sumar los abonos, el arqueo daría de más sin que nada fallara.
 *
 * Estas pruebas recorren el turno completo justamente para demostrarlo.
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
    $this->tarjeta = PaymentMethod::query()->where('code', 'CARD')->sole();
    $this->categoriaGasto = ExpenseCategory::query()->where('status', 'active')->firstOrFail();

    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);

    $rol = Role::query()->where('name', RoleTemplates::MANAGER)->sole();
    $usuario = User::factory()->create();

    $this->gerente = TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'G001',
        'has_all_branches' => true,
        'default_role_id' => $rol->id,
    ]);

    $usuario->syncRoles([$rol]);
    app(ManageMembershipPin::class)->set($this->gerente, '1111');

    app(TenantContext::class)->forget();

    /** Abre la caja con el fondo indicado y devuelve su ULID. */
    $this->abrirCaja = fn (string $fondo = '500.00'): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => $fondo,
        ])
        ->assertCreated()
        ->json('data.ulid');

    /** Vende y cobra con el método indicado. */
    $this->venderYCobrar = function (string $cantidad, PaymentMethod $metodo, string $propina = '0.00'): void {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->comida->ulid, 'quantity' => $cantidad]],
            ])
            ->assertCreated();

        $total = bcmul($cantidad, '100.00', 2);

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
                'payments' => [array_filter([
                    'payment_method_ulid' => $metodo->ulid,
                    'amount' => $total,
                    'tip_amount' => $propina === '0.00' ? null : $propina,
                    'tendered_amount' => bcadd($total, $propina, 2),
                ])],
            ])
            ->assertOk();
    };

    /** El corte. */
    $this->corte = fn (string $sesion) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-sessions/{$sesion}/cut");

    /** Declara y cierra. */
    $this->declararYCerrar = function (string $sesion, string $efectivo, ?string $tarjeta = null): void {
        $montos = [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => $efectivo]];

        if ($tarjeta !== null) {
            $montos[] = ['payment_method_ulid' => $this->tarjeta->ulid, 'declared_amount' => $tarjeta];
        }

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-sessions/{$sesion}/declarations", [
                'moment' => 'close',
                'declarations' => $montos,
            ])
            ->assertOk();

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-sessions/{$sesion}/close")
            ->assertOk();
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// El esperado
// ---------------------------------------------------------------------------

it('el efectivo esperado empieza siendo el FONDO', function () {
    $sesion = ($this->abrirCaja)('500.00');

    ($this->corte)($sesion)
        ->assertOk()
        ->assertJsonPath('data.expected_cash', '500.00');
});

it('suma los pagos en efectivo y RESTA el cambio', function () {
    $sesion = ($this->abrirCaja)('500.00');

    // 300 cobrados en efectivo con 20 de propina: entra 320 y el cambio ya está descontado en el propio pago.
    ($this->venderYCobrar)('3', $this->efectivo, '20.00');

    ($this->corte)($sesion)
        ->assertOk()
        // 500 de fondo + 300 del pago + 20 de propina = 820.
        ->assertJsonPath('data.expected_cash', '820.00');
});

it('una TARJETA no toca el cajón, y aparece con su propio esperado', function () {
    $sesion = ($this->abrirCaja)('500.00');

    ($this->venderYCobrar)('4', $this->tarjeta);

    $corte = ($this->corte)($sesion)->assertOk();

    // El cajón sigue con el fondo: la tarjeta no lo mueve.
    $corte->assertJsonPath('data.expected_cash', '500.00');

    $porMetodo = collect($corte->json('data.by_method'))->keyBy('method');

    expect($porMetodo['Tarjeta']['expected'])->toBe('400.00');
});

it('el gasto desde caja y la propina liquidada BAJAN el esperado, sin tocar la fórmula', function () {
    // Es el punto entero: cada tipo nuevo entra solo en la suma. Con la fórmula enumerada de §6.5 habría que editarla, y
    // el día que alguien olvidara un tipo el arqueo daría de más sin que nada fallara.
    $sesion = ($this->abrirCaja)('500.00');

    // Una mesera con propina.
    app(TenantContext::class)->set($this->tenant->id);
    $usuario = User::factory()->create();
    $mesera = TenantMembership::factory()->create([
        'user_id' => $usuario->id, 'employee_code' => 'W001', 'has_all_branches' => true,
    ]);
    app(TenantContext::class)->forget();

    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Barra',
            'waiter_ulid' => $mesera->ulid,
        ])
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->comida->ulid, 'quantity' => '5']],
        ])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [[
                'payment_method_ulid' => $this->efectivo->ulid,
                'amount' => '500.00',
                'tip_amount' => '50.00',
                'tendered_amount' => '550.00',
            ]],
        ])
        ->assertOk();

    // 500 + 500 + 50 = 1050.
    ($this->corte)($sesion)->assertJsonPath('data.expected_cash', '1050.00');

    // Un gasto de 200 desde caja.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/expenses', [
            'branch_ulid' => $this->branch->ulid,
            'expense_category_ulid' => $this->categoriaGasto->ulid,
            'source' => 'cash_session',
            'amount' => '200.00',
            'description' => 'Garrafones',
        ])
        ->assertCreated();

    ($this->corte)($sesion)->assertJsonPath('data.expected_cash', '850.00');

    // Y la propina liquidada: 50 que salen del cajón.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tip-settlements', [
            'membership_ulid' => $mesera->ulid,
            'branch_ulid' => $this->branch->ulid,
            'amount' => '50.00',
        ])
        ->assertCreated();

    ($this->corte)($sesion)->assertJsonPath('data.expected_cash', '800.00');
});

it('el RETIRO baja el esperado', function () {
    $sesion = ($this->abrirCaja)('1000.00');

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'pos.sessions.withdraw',
        ])
        ->assertCreated()
        ->json('data.token');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$sesion}/withdrawals", [
            'amount' => '400.00',
            'reason' => 'Va al banco',
            'authorization_token' => $token,
        ])
        ->assertCreated();

    ($this->corte)($sesion)->assertJsonPath('data.expected_cash', '600.00');
});

// ---------------------------------------------------------------------------
// La diferencia
// ---------------------------------------------------------------------------

it('la diferencia se ASIENTA como movimiento tipado', function () {
    // §6.5: la diferencia es ella misma un movimiento. Así el diario cuadra consigo mismo y la diferencia queda con
    // nombre, monto y actor — que es lo que permite preguntar «¿a esta persona le falta dinero seguido?».
    $sesion = ($this->abrirCaja)('500.00');
    ($this->venderYCobrar)('3', $this->efectivo);

    // Esperado 800; se declaran 780: faltan 20.
    ($this->declararYCerrar)($sesion, '780.00');

    app(TenantContext::class)->set($this->tenant->id);

    $diferencia = FinancialMovement::query()
        ->where('type', FinancialMovementType::CountDifference->value)
        ->sole();

    expect((string) $diferencia->amount)->toBe('-20.00');
    expect($diferencia->affects_cash_drawer)->toBeTrue();
});

it('si SOBRA dinero, la diferencia es positiva', function () {
    // El signo se conserva tal cual: positivo es que había más de lo esperado. Normalizarlo perdería la mitad de la
    // información.
    $sesion = ($this->abrirCaja)('500.00');

    ($this->declararYCerrar)($sesion, '530.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect((string) FinancialMovement::query()
        ->where('type', FinancialMovementType::CountDifference->value)
        ->sole()
        ->amount)->toBe('30.00');
});

it('si CUADRA no se asienta nada', function () {
    // «Cuadró» es la ausencia de diferencia, no una diferencia de cero. Un asiento por cada turno que cuadra llenaría el
    // diario de renglones que no dicen nada — y el diario rechaza los ceros desde el paso 4.
    $sesion = ($this->abrirCaja)('500.00');

    ($this->declararYCerrar)($sesion, '500.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(FinancialMovement::query()->where('type', FinancialMovementType::CountDifference->value)->count())->toBe(0);
});

it('la diferencia de un método que NO es efectivo no se asienta, pero SÍ se ve', function () {
    // El diario modela el dinero del negocio: una discrepancia con la terminal bancaria no cambia cuánto dinero hay,
    // cambia qué hay que reclamarle al banco. Meterlo en el diario haría que un error de la terminal se viera como un
    // faltante de caja, que es una acusación muy distinta.
    $sesion = ($this->abrirCaja)('500.00');
    ($this->venderYCobrar)('4', $this->tarjeta);

    // Efectivo cuadra; la tarjeta declara 380 de 400.
    ($this->declararYCerrar)($sesion, '500.00', '380.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(FinancialMovement::query()->where('type', FinancialMovementType::CountDifference->value)->count())->toBe(0);

    app(TenantContext::class)->forget();

    $porMetodo = collect(($this->corte)($sesion)->json('data.by_method'))->keyBy('method');

    expect($porMetodo['Tarjeta']['difference'])->toBe('-20.00');
});

it('un método declarado y NO contado se distingue de uno declarado en cero', function () {
    // Son dos cosas distintas —«declaró que no había nada» y «no lo contó»— y pintarlas igual haría que un método
    // olvidado se viera como un faltante del total.
    $sesion = ($this->abrirCaja)('500.00');
    ($this->venderYCobrar)('4', $this->tarjeta);

    ($this->declararYCerrar)($sesion, '500.00');

    $porMetodo = collect(($this->corte)($sesion)->json('data.by_method'))->keyBy('method');

    expect($porMetodo['Tarjeta']['declared'])->toBeNull();
    expect($porMetodo['Tarjeta']['difference'])->toBeNull();
    expect($porMetodo['Efectivo']['declared'])->toBe('500.00');
});

// ---------------------------------------------------------------------------
// El precorte ciego
// ---------------------------------------------------------------------------

it('quien sólo puede DECLARAR no ve el corte', function () {
    // Ahí está todo el mecanismo del precorte ciego: declarar y ver el corte son permisos distintos. No hace falta una
    // versión recortada del reporte, que acabaría filtrando el esperado por un descuido de la pantalla.
    $sesion = ($this->abrirCaja)('500.00');

    app(TenantContext::class)->set($this->tenant->id);

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->sole();
    $cajero->revokePermissionTo('finance.cuts.view');
    $cajero->givePermissionTo('pos.sessions.precount');

    $usuario = User::factory()->create();

    TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'C900',
        'has_all_branches' => true,
        'default_role_id' => $cajero->id,
    ]);

    $usuario->syncRoles([$cajero]);

    app(TenantContext::class)->forget();

    // Puede declarar…
    $this->actingAsSpa($usuario, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$sesion}/declarations", [
            'moment' => 'precount',
            'declarations' => [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => '480.00']],
        ])
        ->assertOk();

    // …y no puede ver el esperado.
    $this->actingAsSpa($usuario, $this->tenant->id)
        ->getJson("/api/v1/pos-sessions/{$sesion}/cut")
        ->assertStatus(403);
});

it('el precorte NO afecta al corte del cierre', function () {
    // El precorte es una comprobación a media tarde; la diferencia se calcula de lo declarado AL CERRAR.
    $sesion = ($this->abrirCaja)('500.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$sesion}/declarations", [
            'moment' => 'precount',
            'declarations' => [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => '100.00']],
        ])
        ->assertOk();

    ($this->declararYCerrar)($sesion, '500.00');

    app(TenantContext::class)->set($this->tenant->id);

    // Cuadró: el precorte de 100 no cuenta.
    expect(FinancialMovement::query()->where('type', FinancialMovementType::CountDifference->value)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// El corte con la caja abierta
// ---------------------------------------------------------------------------

it('el corte se puede mirar con la caja ABIERTA', function () {
    // No es una foto del cierre, es la cuenta de ahora: quien supervisa a media tarde quiere ver cómo va.
    $sesion = ($this->abrirCaja)('500.00');
    ($this->venderYCobrar)('2', $this->efectivo);

    ($this->corte)($sesion)
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.expected_cash', '700.00')
        // Nada declarado todavía, y se lee como lo que es.
        ->assertJsonPath('data.total_declared', '0.00');
});

it('el corte de un negocio es invisible para otro', function () {
    $sesion = ($this->abrirCaja)('500.00');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/pos-sessions/{$sesion}/cut")
        ->assertStatus(404);
});

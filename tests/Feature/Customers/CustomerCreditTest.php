<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
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
use Illuminate\Support\Str;

/**
 * CLIENTES Y CRÉDITO (§8, paso 17)
 *
 * ## Lo que el crédito MATA es la «cuenta que nunca se cierra»
 *
 * §6.3 la prohíbe, y el crédito es el mecanismo para el fiado. Sin él, un negocio que fía deja cuentas abiertas para
 * siempre —justo lo prohibido— y el corte de cada noche arrastra consumos de hace semanas. Con crédito, la cuenta queda
 * **pagada** y el fiado pasa a ser un saldo con nombre.
 *
 * ## Y por qué el cargo es síncrono mientras el asiento no
 *
 * Si el cargo al saldo se hiciera por evento —después del commit— una cuenta podría quedar pagada sin cargar: el
 * negocio habría regalado la comida y el estado de cuenta no lo sabría. El asiento del diario sí puede llegar tarde.
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

    // El crédito nace INACTIVO al provisionar (D232): fiar es una decisión del negocio, no algo que se enciende solo.
    $this->credito = PaymentMethod::query()->where('code', 'CUSTOMER_CREDIT')->sole();
    $this->credito->update(['status' => 'active']);

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

    $this->abrirCaja = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated();

    /** Da de alta un cliente con el límite indicado y devuelve su ULID. */
    $this->clienteCon = function (string $limite): string {
        $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/customers', ['name' => 'Don Chuy', 'phone' => '5551234567'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->patchJson("/api/v1/customers/{$ulid}/credit", ['credit_limit' => $limite, 'is_enabled' => true])
            ->assertOk();

        return $ulid;
    };

    /** Abre una cuenta con N comidas, la identifica con el cliente y devuelve su ULID. */
    $this->cuentaDe = function (string $cantidad, ?string $clienteUlid = null): string {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra 1'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->comida->ulid, 'quantity' => $cantidad]],
            ])
            ->assertCreated();

        if ($clienteUlid !== null) {
            $this->actingAsSpa($this->owner, $this->tenant->id)
                ->postJson("/api/v1/pos-accounts/{$cuenta}/customer", ['customer_ulid' => $clienteUlid])
                ->assertOk();
        }

        return $cuenta;
    };

    /** Cobra a crédito. */
    $this->fiar = fn (string $cuenta, string $monto, ?string $token = null) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [array_filter([
                'payment_method_ulid' => $this->credito->ulid,
                'amount' => $monto,
                'authorization_token' => $token,
            ])],
        ]);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// El alta express
// ---------------------------------------------------------------------------

it('da de alta un cliente con NOMBRE y nada más', function () {
    // D43: pedirle la razón social a alguien que está pagando un café es lo que hace que nadie registre clientes.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/customers', ['name' => 'Señora del 5'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Señora del 5')
        // La fila de crédito nace con el cliente, en cero: con límite cero, «no puede fiar» sale del propio dato en
        // lugar de un `null` que cada pantalla interpretaría a su manera.
        ->assertJsonPath('data.credit.limit', '0.00')
        ->assertJsonPath('data.credit.available', '0.00');
});

it('dos clientes no comparten teléfono', function () {
    // Es el identificador del mostrador: dos iguales acaban con el saldo de uno cargado al otro.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/customers', ['name' => 'Don Chuy', 'phone' => '5551234567'])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/customers', ['name' => 'Otro', 'phone' => '5551234567'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['phone']]);
});

// ---------------------------------------------------------------------------
// Fiar
// ---------------------------------------------------------------------------

it('cobrar a crédito deja la cuenta PAGADA y carga el saldo', function () {
    // Esto es lo que mata la «cuenta que nunca se cierra»: el fiado deja de ser una cuenta abierta.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);

    ($this->fiar)($cuenta, '300.00')
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}")
        ->assertOk()
        ->assertJsonPath('data.credit.balance', '300.00')
        ->assertJsonPath('data.credit.available', '700.00');
});

it('fiar NO mueve el cajón, pero sí queda asentado como derecho de cobro', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);

    ($this->fiar)($cuenta, '300.00')->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // El PAGO no mueve el cajón: no entró dinero.
    $pago = FinancialMovement::query()->where('type', FinancialMovementType::Payment->value)->sole();
    expect($pago->affects_cash_drawer)->toBeFalse();

    // Y hay un asiento de crédito concedido: es lo que distingue «vendí 10 000» de «cobré 8 000 y me deben 2 000».
    $concedido = FinancialMovement::query()->where('type', FinancialMovementType::CreditGranted->value)->sole();
    expect((string) $concedido->amount)->toBe('300.00');
});

it('una cuenta SIN cliente no se puede fiar', function () {
    // Un consumo fiado sin nombre es dinero que nadie va a cobrar.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaDe)('3');

    ($this->fiar)($cuenta, '300.00')->assertStatus(409);
});

it('pasarse del límite pide PIN, con el permiso que falta', function () {
    // 409 y no 422: el cliente, el monto y el método son correctos. Lo que falta es que alguien decida fiarle de más.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('200.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);

    ($this->fiar)($cuenta, '300.00')
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'finance.customer_credit.manage');
});

it('con PIN se puede pasar del límite', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('200.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'finance.customer_credit.manage',
        ])
        ->assertCreated()
        ->json('data.token');

    ($this->fiar)($cuenta, '300.00', $token)->assertOk()->assertJsonPath('data.status', 'paid');

    // Y el disponible NO queda en negativo: «menos cien disponibles» no significa nada en el mostrador.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}")
        ->assertJsonPath('data.credit.balance', '300.00')
        ->assertJsonPath('data.credit.available', '0.00');
});

it('un crédito SUSPENDIDO no fía, y conserva su límite', function () {
    // Un cliente que se atrasó no pierde su límite, pierde el permiso de usarlo: volver a habilitarlo no exige
    // recapturar nada.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$cliente}/credit", ['credit_limit' => '1000.00', 'is_enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.credit.limit', '1000.00')
        ->assertJsonPath('data.credit.is_enabled', false);

    $cuenta = ($this->cuentaDe)('1', $cliente);

    ($this->fiar)($cuenta, '100.00')->assertStatus(409);
});

// ---------------------------------------------------------------------------
// Abonar
// ---------------------------------------------------------------------------

it('un abono baja el saldo y AFECTA EL CAJÓN', function () {
    // Es la mitad que falta para que el corte cuadre: sin abonos, un turno que recibió dos mil pesos de fiado daría dos
    // mil de más sin explicación.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);
    ($this->fiar)($cuenta, '300.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '200.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertCreated()
        // El movimiento va en NEGATIVO: un abono resta de lo que el cliente debe.
        ->assertJsonPath('data.amount', '-200.00')
        ->assertJsonPath('data.balance_after', '100.00');

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::CreditRepayment->value)->sole();

    expect($asiento->affects_cash_drawer)->toBeTrue();
    expect($asiento->pos_session_id)->not->toBeNull();
});

it('no se abona más de lo que se debe', function () {
    // Un saldo negativo no significa nada: si el cliente entregó de más, se le da su cambio.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('1', $cliente);
    ($this->fiar)($cuenta, '100.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '500.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertStatus(409);
});

it('sin caja abierta no se abona', function () {
    $cliente = ($this->clienteCon)('1000.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertStatus(409);
});

it('un abono no se recibe con el crédito del cliente (422)', function () {
    // Pagar lo que se debe fiando más no es un abono: la deuda bajaría sin que entrara un peso, y el diario asentaría un
    // «abono» que no movió nada.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $this->credito->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.payment_method_ulid.0', fn (string $m) => str_contains($m, 'Crédito del cliente'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}")
        ->assertOk()
        ->assertJsonPath('data.credit.balance', '300.00');
});

it('un abono no se recibe con un método de pago desactivado (422)', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    // El negocio dejó de aceptar tarjeta: un selector en caché no puede seguir registrando abonos con ella.
    app(TenantContext::class)->set($this->tenant->id);
    $tarjeta = PaymentMethod::query()->where('code', 'CARD')->sole();
    $tarjeta->update(['status' => 'inactive']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $tarjeta->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.payment_method_ulid.0', fn (string $m) => str_contains($m, 'Tarjeta'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}")
        ->assertOk()
        ->assertJsonPath('data.credit.balance', '300.00');
});

it('un abono con una sucursal o un método que no existen es 422, no 404', function () {
    // El cliente sí existe: lo que está mal es un campo del formulario, y así se dice.
    $cliente = ($this->clienteCon)('1000.00');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'amount' => '100.00',
            'payment_method_ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['branch_ulid', 'payment_method_ulid']);
});

it('los errores del límite y del abono hablan en español', function () {
    $cliente = ($this->clienteCon)('1000.00');

    // Antes: «The credit limit field must be a number.»
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$cliente}/credit", ['credit_limit' => 'mucho', 'is_enabled' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.credit_limit.0', fn (string $m) => str_contains($m, 'límite de crédito'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$cliente}/credit", ['credit_limit' => '100.00'])
        ->assertStatus(422)
        ->assertJsonPath('errors.is_enabled.0', fn (string $m) => str_contains($m, 'crédito'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '0',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.amount.0', fn (string $m) => str_contains($m, 'monto'))
        ->assertJsonPath('errors.payment_method_ulid.0', fn (string $m) => str_contains($m, 'método de pago'));
});

// ---------------------------------------------------------------------------
// El estado de cuenta
// ---------------------------------------------------------------------------

it('el estado de cuenta lleva el saldo DESPUÉS de cada movimiento', function () {
    // Es lo que permite contestar «¿cuánto debía el 3 de marzo?» sin sumar la historia entera — y detectar una
    // desviación de la proyección.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');

    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();
    ($this->fiar)(($this->cuentaDe)('2', $cliente), '200.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertCreated();

    $movimientos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}/credit-movements")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->json('data');

    // Más recientes primero: el abono, y el saldo que dejó.
    expect($movimientos[0]['type'])->toBe('repayment');
    expect($movimientos[0]['balance_after'])->toBe('400.00');
});

it('el estado de cuenta dice de dónde vino cada movimiento con una clave estable, nunca con la clase PHP', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);
    ($this->fiar)($cuenta, '300.00')->assertOk();

    // Un abono no nace de otro documento: él mismo es el documento (el diario lo cita por su ULID).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => (string) Str::ulid(),
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.source_type', null)
        ->assertJsonPath('data.source_label', null);

    $movimientos = collect($this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$cliente}/credit-movements")
        ->assertOk()
        ->json('data'));

    $cargo = $movimientos->firstWhere('type', 'charge');

    // Antes: «App\Modules\Pos\Infrastructure\Models\PosAccount» — un nombre de clase que cambia con un refactor y
    // que ninguna pantalla debería tener que conocer.
    expect($cargo['source_type'])->toBe('pos_account');
    expect($cargo['source_label'])->toBe('Cuenta del punto de venta');
    expect($cargo['source_ulid'])->toBe($cuenta);

    expect(json_encode($movimientos->all()))->not->toContain('Modules');
});

// ---------------------------------------------------------------------------
// Una sola vez, con su referencia (D378)
// ---------------------------------------------------------------------------

it('el MISMO abono enviado dos veces se registra una sola vez', function () {
    // Un doble clic, o la red que se cae y el reintento: antes eran dos abonos y el cliente debía menos de lo real.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $abono = [
        'idempotency_key' => (string) Str::ulid(),
        'branch_ulid' => $this->branch->ulid,
        'amount' => '200.00',
        'payment_method_ulid' => $this->efectivo->ulid,
    ];

    $primero = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", $abono)
        ->assertCreated()
        ->json('data.ulid');

    // La repetición devuelve el MISMO abono, aunque ya no «quepa» en lo que debe (100 < 200).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", $abono)
        ->assertCreated()
        ->assertJsonPath('data.ulid', $primero)
        ->assertJsonPath('data.balance_after', '100.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(CustomerCreditMovement::query()->where('type', 'repayment')->count())->toBe(1);
    expect((string) CustomerCredit::query()->sole()->balance)->toBe('100.00');

    // Y el cajón lo espera una vez: un solo asiento en el diario.
    expect(FinancialMovement::query()->where('type', FinancialMovementType::CreditRepayment->value)->count())->toBe(1);
});

it('una llave REUSADA con otro monto se rechaza y no mueve nada', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $llave = (string) Str::ulid();

    $abonar = fn (string $monto) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [
            'idempotency_key' => $llave,
            'branch_ulid' => $this->branch->ulid,
            'amount' => $monto,
            'payment_method_ulid' => $this->efectivo->ulid,
        ]);

    $abonar('100.00')->assertCreated();

    // Devolver el abono de 100 diría que se registró uno de 50 que nadie registró.
    $abonar('50.00')->assertStatus(409);

    app(TenantContext::class)->set($this->tenant->id);

    expect((string) CustomerCredit::query()->sole()->balance)->toBe('200.00');
});

it('con un método que pide referencia, el abono la exige y la guarda', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $transferencia = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => PaymentMethod::query()->where('code', 'TRANSFER')->sole(),
    );

    $abono = [
        'idempotency_key' => (string) Str::ulid(),
        'branch_ulid' => $this->branch->ulid,
        'amount' => '150.00',
        'payment_method_ulid' => $transferencia->ulid,
    ];

    // Sin ella no se puede conciliar: antes el abono pasaba y el folio se perdía.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", $abono)
        ->assertStatus(422)
        ->assertJsonPath('errors.reference.0', fn (string $m) => str_contains($m, 'referencia'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-repayments", [...$abono, 'reference' => 'SPEI 4827103'])
        ->assertCreated()
        ->assertJsonPath('data.reference', 'SPEI 4827103');
});

// ---------------------------------------------------------------------------
// Ajustes (D378)
// ---------------------------------------------------------------------------

it('un ajuste sin PIN pide la autorización y no mueve nada', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", [
            'idempotency_key' => (string) Str::ulid(),
            'direction' => 'decrease',
            'amount' => '100.00',
            'reason' => 'Se le cobró dos veces el refresco',
        ])
        ->assertStatus(409)
        ->assertJsonPath('required_permission', 'finance.customer_credit.adjust');

    app(TenantContext::class)->set($this->tenant->id);

    expect((string) CustomerCredit::query()->sole()->balance)->toBe('300.00');
});

it('con el PIN del propietario, el ajuste condona con su motivo, su autorizador y sin tocar la caja', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    $propietaria = propietariaConPinParaAjustes($this->tenant->id);

    $asientosAntes = app(TenantContext::class)->runFor($this->tenant->id, fn () => FinancialMovement::query()->count());

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'AJ01',
            'pin' => '2222',
            'permission' => 'finance.customer_credit.adjust',
        ])
        ->assertCreated()
        ->json('data.token');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", [
            'idempotency_key' => (string) Str::ulid(),
            'direction' => 'decrease',
            'amount' => '100.00',
            'reason' => 'Se le cobró dos veces el refresco',
            'authorization_token' => $token,
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'adjustment')
        ->assertJsonPath('data.amount', '-100.00')
        ->assertJsonPath('data.balance_after', '200.00')
        ->assertJsonPath('data.notes', 'Se le cobró dos veces el refresco');

    app(TenantContext::class)->set($this->tenant->id);

    $ajuste = CustomerCreditMovement::query()->where('type', 'adjustment')->sole();

    // El actor real de una acción sensible es quien la AUTORIZÓ.
    expect((int) $ajuste->authorized_by_membership_id)->toBe($propietaria->id);

    // No entró ni salió dinero: el diario y el arqueo no se enteran.
    expect(FinancialMovement::query()->count())->toBe($asientosAntes);
});

it('el gerente NO autoriza ajustes: se condonaría deudas a sí mismo', function () {
    // El gerente administra el crédito y registra el ajuste; la firma es del propietario (D378).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'finance.customer_credit.adjust',
        ])
        ->assertStatus(422);
});

it('un ajuste no condona más de lo que debe, y no gasta la firma', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    propietariaConPinParaAjustes($this->tenant->id);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'AJ01',
            'pin' => '2222',
            'permission' => 'finance.customer_credit.adjust',
        ])
        ->json('data.token');

    $ajustar = fn (string $monto) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", [
            'idempotency_key' => (string) Str::ulid(),
            'direction' => 'decrease',
            'amount' => $monto,
            'reason' => 'Condonación por cierre del negocio del cliente',
            'authorization_token' => $token,
        ]);

    $ajustar('500.00')->assertStatus(409);

    // La firma sigue sirviendo: se rechazó antes de gastarla.
    $ajustar('300.00')->assertCreated()->assertJsonPath('data.balance_after', '0.00');
});

it('un ajuste repetido con la misma llave no condona dos veces ni pide otra firma', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('3', $cliente), '300.00')->assertOk();

    propietariaConPinParaAjustes($this->tenant->id);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'AJ01',
            'pin' => '2222',
            'permission' => 'finance.customer_credit.adjust',
        ])
        ->json('data.token');

    $ajuste = [
        'idempotency_key' => (string) Str::ulid(),
        'direction' => 'decrease',
        'amount' => '100.00',
        'reason' => 'Se le cobró dos veces el refresco',
    ];

    $primero = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", [...$ajuste, 'authorization_token' => $token])
        ->assertCreated()
        ->json('data.ulid');

    // El reintento llega sin la firma —ya se gastó— y devuelve lo hecho.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", $ajuste)
        ->assertCreated()
        ->assertJsonPath('data.ulid', $primero);

    app(TenantContext::class)->set($this->tenant->id);

    expect((string) CustomerCredit::query()->sole()->balance)->toBe('200.00');
});

it('un ajuste hacia ARRIBA corrige un cargo que faltó', function () {
    $cliente = ($this->clienteCon)('1000.00');

    propietariaConPinParaAjustes($this->tenant->id);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'AJ01',
            'pin' => '2222',
            'permission' => 'finance.customer_credit.adjust',
        ])
        ->json('data.token');

    // Sin caja abierta: un ajuste no entra a ningún cajón.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$cliente}/credit-adjustments", [
            'idempotency_key' => (string) Str::ulid(),
            'direction' => 'increase',
            'amount' => '80.00',
            'reason' => 'El cargo del jueves no se registró por un corte de luz',
            'authorization_token' => $token,
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', '80.00')
        ->assertJsonPath('data.balance_after', '80.00');
});

it('la proyección coincide con el último balance_after', function () {
    // El saldo es proyección y no verdad: si dejaran de coincidir, algo escribió por fuera de la única puerta.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('4', $cliente), '400.00')->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $credito = CustomerCredit::query()->sole();
    $ultimo = CustomerCreditMovement::query()->orderByDesc('id')->first();

    expect((string) $credito->balance)->toBe((string) $ultimo->balance_after);
});

it('un movimiento de crédito es INMUTABLE', function () {
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    ($this->fiar)(($this->cuentaDe)('1', $cliente), '100.00')->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // Corregir es un ajuste en contra: el estado de cuenta que el cliente ya vio no cambia de contenido.
    expect(fn () => CustomerCreditMovement::query()->sole()->update(['amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

it('el cargo es IDEMPOTENTE por cuenta', function () {
    // Re-registrar el cargo de la misma cuenta no duplica la deuda. Es la misma llave y el mismo motivo que el diario.
    ($this->abrirCaja)();
    $cliente = ($this->clienteCon)('1000.00');
    $cuenta = ($this->cuentaDe)('3', $cliente);
    ($this->fiar)($cuenta, '300.00')->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $modelo = \App\Modules\Customers\Infrastructure\Models\Customer::query()->sole();

    app(\App\Modules\Customers\Application\RecordCreditMovement::class)->record(
        customer: $modelo,
        type: CreditMovementType::Charge,
        amount: '300.00',
        actorMembershipId: (int) $this->gerente->id,
        sourceType: \App\Modules\Pos\Infrastructure\Models\PosAccount::class,
        sourceUlid: $cuenta,
    );

    expect(CustomerCreditMovement::query()->count())->toBe(1);
    expect((string) CustomerCredit::query()->sole()->balance)->toBe('300.00');
});

it('los clientes de un negocio son invisibles para otro', function () {
    ($this->clienteCon)('1000.00');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * Una propietaria con PIN, para autorizar ajustes de crédito: el gerente no puede (D378).
 */
function propietariaConPinParaAjustes(int $tenantId): TenantMembership
{
    return app(TenantContext::class)->runFor($tenantId, function (): TenantMembership {
        $rol = Role::query()->where('name', RoleTemplates::OWNER)->sole();
        $usuario = User::factory()->create();

        $propietaria = TenantMembership::factory()->create([
            'user_id' => $usuario->id,
            'employee_code' => 'AJ01',
            'has_all_branches' => true,
            'default_role_id' => $rol->id,
        ]);

        $usuario->syncRoles([$rol]);
        app(ManageMembershipPin::class)->set($propietaria, '2222');

        return $propietaria;
    });
}

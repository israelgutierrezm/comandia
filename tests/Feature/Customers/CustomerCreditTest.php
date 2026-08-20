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
            'branch_ulid' => $this->branch->ulid,
            'amount' => '100.00',
            'payment_method_ulid' => $this->efectivo->ulid,
        ])
        ->assertStatus(409);
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

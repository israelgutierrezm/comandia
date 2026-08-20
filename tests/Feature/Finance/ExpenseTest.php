<?php

declare(strict_types=1);

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\Expense;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * GASTOS DESDE CAJA Y FUERA DE CAJA (§6.5, paso 16)
 *
 * ## Por qué el gasto desde caja es NECESARIO y no un extra
 *
 * D235 lo puso entre las cuatro cosas sin las que el POS no funciona, y la razón es aritmética: **el cajero paga los
 * garrafones con dinero de la caja**. Un arqueo que no conoce esa salida no cuadra nunca, y una diferencia que siempre
 * está deja de significar nada — que es peor que no tener arqueo, porque nadie vuelve a mirarla.
 *
 * ## Y por qué el umbral existe al revés que en el cajón
 *
 * Abrir el cajón pide PIN siempre (D248); un gasto, sólo por encima de un monto. Si todo gasto lo pidiera, el cajero
 * dejaría de registrar los 40 pesos de hielo para no ir a buscar al gerente: el dinero sale igual y el arqueo se
 * descuadra **sin rastro**.
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

    $this->categoria = ExpenseCategory::query()->where('status', 'active')->firstOrFail();
    $this->transferencia = PaymentMethod::query()->where('code', 'TRANSFER')->sole();
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

    $this->gastar = fn (array $datos) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/expenses', array_merge([
            'branch_ulid' => $this->branch->ulid,
            'expense_category_ulid' => $this->categoria->ulid,
        ], $datos));

    $this->autorizacion = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'finance.expenses.authorize_above_threshold',
        ])
        ->assertCreated()
        ->json('data.token');
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Desde caja
// ---------------------------------------------------------------------------

it('registra un gasto desde caja y lo ata al turno abierto', function () {
    ($this->abrirCaja)();

    $respuesta = ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '250.00',
        'description' => 'Garrafones de agua',
    ])
        ->assertCreated()
        ->assertJsonPath('data.source', 'cash_session')
        // Que toca el arqueo lo resuelve el servidor: la pantalla no debería deducirlo del valor del enum.
        ->assertJsonPath('data.affects_cash_drawer', true);

    app(TenantContext::class)->set($this->tenant->id);

    $gasto = Expense::query()->where('ulid', $respuesta->json('data.ulid'))->sole();

    // El turno lo resuelve el SERVIDOR. Aceptarlo del cliente dejaría que alguien cargara un gasto al turno de otro.
    expect($gasto->pos_session_id)->not->toBeNull();
});

it('sin caja abierta NO se registra un gasto desde caja', function () {
    // Sin turno sería dinero que salió de ningún cajón: el arqueo no podría atribuirlo.
    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '250.00',
        'description' => 'Garrafones de agua',
    ])->assertStatus(409);
});

it('asienta el gasto en el diario, EN NEGATIVO y en la misma transacción', function () {
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '250.00',
        'description' => 'Garrafones de agua',
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Expense->value)->sole();

    // Un gasto RESTA. En positivo aumentaría el efectivo esperado del corte, que es justo al revés.
    expect((string) $asiento->amount)->toBe('-250.00');

    // Y toca el cajón: sin método de pago, el diario lo decide por el tipo — el dinero salió del efectivo del turno.
    expect($asiento->affects_cash_drawer)->toBeTrue();
    expect($asiento->pos_session_id)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Fuera de caja
// ---------------------------------------------------------------------------

it('un gasto FUERA de caja no toca el arqueo y exige método de pago', function () {
    // Mezclarlos haría que el arqueo del cajero cargara con la renta del local.
    ($this->gastar)([
        'source' => 'outside_cash',
        'amount' => '8000.00',
        'description' => 'Renta del local',
        'authorization_token' => ($this->autorizacion)(),
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['payment_method_ulid']]);

    ($this->gastar)([
        'source' => 'outside_cash',
        'amount' => '8000.00',
        'description' => 'Renta del local',
        'payment_method_ulid' => $this->transferencia->ulid,
        'authorization_token' => ($this->autorizacion)(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.affects_cash_drawer', false);

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Expense->value)->sole();

    expect($asiento->affects_cash_drawer)->toBeFalse();
    expect($asiento->pos_session_id)->toBeNull();
});

it('sin el permiso de gasto FUERA de caja, 403', function () {
    // Son dos decisiones distintas: el cajero paga los garrafones y no por eso debería registrar la renta del local.
    // El permiso de la ruta cubre el de caja; éste se comprueba contra el `source` recibido.
    app(TenantContext::class)->set($this->tenant->id);

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->sole();
    $cajero->revokePermissionTo('finance.expenses.create_outside_cash');
    $cajero->givePermissionTo('finance.expenses.create_from_cash');

    $usuario = User::factory()->create();

    TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'C900',
        'has_all_branches' => true,
        'default_role_id' => $cajero->id,
    ]);

    $usuario->syncRoles([$cajero]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->postJson('/api/v1/expenses', [
            'branch_ulid' => $this->branch->ulid,
            'expense_category_ulid' => $this->categoria->ulid,
            'source' => 'outside_cash',
            'amount' => '100.00',
            'description' => 'Renta del local',
            'payment_method_ulid' => $this->transferencia->ulid,
        ])
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// El umbral
// ---------------------------------------------------------------------------

it('por DEBAJO del umbral no pide PIN', function () {
    // Si todo gasto lo pidiera, el cajero dejaría de registrar los 40 pesos de hielo y el arqueo se descuadraría sin
    // rastro.
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '40.00',
        'description' => 'Hielo',
    ])
        ->assertCreated()
        ->assertJsonPath('data.authorized_by', null);
});

it('por ENCIMA del umbral pide PIN, con el permiso que falta', function () {
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '1500.00',
        'description' => 'Reparación del refrigerador',
    ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'finance.expenses.authorize_above_threshold');
});

it('con PIN pasa, y registra a las DOS personas', function () {
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '1500.00',
        'description' => 'Reparación del refrigerador',
        'authorization_token' => ($this->autorizacion)(),
    ])
        ->assertCreated()
        ->assertJsonPath('data.created_by.employee_code', 'P001')
        ->assertJsonPath('data.authorized_by.employee_code', 'G001');
});

it('el umbral es exactamente eso: EN el umbral no pide PIN', function () {
    // «Hasta mil sin autorizar» es como lo lee quien lo configura.
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '1000.00',
        'description' => 'Compra de vasos',
    ])->assertCreated();
});

it('el umbral se puede ajustar POR SUCURSAL', function () {
    // El gasto corriente de un bar y de una fonda no se parecen.
    app(TenantContext::class)->set($this->tenant->id);
    app(Settings::class)->setForBranch('finance.expense_authorization_threshold', (int) $this->branch->id, 100.0);
    app(TenantContext::class)->forget();

    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '150.00',
        'description' => 'Hielo',
    ])->assertStatus(409);
});

it('una autorización no sirve para dos gastos', function () {
    ($this->abrirCaja)();

    $token = ($this->autorizacion)();

    ($this->gastar)([
        'source' => 'cash_session', 'amount' => '1500.00',
        'description' => 'Reparación', 'authorization_token' => $token,
    ])->assertCreated();

    ($this->gastar)([
        'source' => 'cash_session', 'amount' => '1500.00',
        'description' => 'Otra reparación', 'authorization_token' => $token,
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// La forma y la inmutabilidad
// ---------------------------------------------------------------------------

it('la descripción es obligatoria', function () {
    // «¿En qué se fue ese dinero?» es la pregunta del arqueo, y una categoría sola no la contesta: «Gastos varios:
    // $800» no explica nada.
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '250.00',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['description']]);
});

it('el comprobante es OPCIONAL', function () {
    // Exigirlo haría que el gasto de 40 pesos de hielo no se registrara, y un gasto sin comprobante es infinitamente
    // mejor que un gasto sin registrar (§6.5).
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session',
        'amount' => '40.00',
        'description' => 'Hielo',
    ])
        ->assertCreated()
        ->assertJsonPath('data.receipt_path', null);
});

it('un gasto es INMUTABLE', function () {
    ($this->abrirCaja)();

    ($this->gastar)([
        'source' => 'cash_session', 'amount' => '250.00', 'description' => 'Garrafones',
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    // Editarlo cambiaría un arqueo ya cerrado. Corregirlo es registrar su reversa.
    expect(fn () => Expense::query()->sole()->update(['amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

it('lista y filtra los gastos', function () {
    ($this->abrirCaja)();

    ($this->gastar)(['source' => 'cash_session', 'amount' => '250.00', 'description' => 'Garrafones'])->assertCreated();

    ($this->gastar)([
        'source' => 'outside_cash',
        'amount' => '500.00',
        'description' => 'Renta',
        'payment_method_ulid' => $this->transferencia->ulid,
    ])->assertCreated();

    $lista = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/expenses')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/expenses?source=cash_session')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Con interpolación y no con concatenación: el candado de endpoints ejercitados busca la ruta en el TEXTO del
    // archivo, y `'/api/v1/expenses/'.$x` deja una comilla justo donde espera el parámetro, así que no la encuentra. Es
    // una limitación real de reconocer llamadas por texto, y la salida correcta es escribirla como el resto del
    // proyecto en lugar de agrandar el patrón hasta que acepte cualquier cosa.
    $ulid = $lista->json('data.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/expenses/{$ulid}")
        ->assertOk();
});

it('los gastos de un negocio son invisibles para otro', function () {
    ($this->abrirCaja)();
    ($this->gastar)(['source' => 'cash_session', 'amount' => '250.00', 'description' => 'Garrafones'])->assertCreated();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/expenses')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

<?php

declare(strict_types=1);

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Domain\Exceptions\FinancialMovementInvariantException;
use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Carbon\CarbonImmutable;

/**
 * EL DIARIO FINANCIERO (ADR-004, §6.5)
 *
 * ## Las tres cosas que lo hacen evidencia
 *
 * 1. **Append-only.** Un asiento que se puede editar no prueba nada. La corrección es una reversa enlazada.
 * 2. **Idempotente por (documento, tipo).** Es lo que hace seguro re-despachar un evento para reparar un fallo de
 *    oyente, que es el mecanismo con el que D220 evitó que una confirmación mintiera.
 * 3. **La bandera del cajón se COPIA.** Si mañana alguien cambia la configuración del método de pago, los cortes de
 *    ayer no cambian con ella.
 *
 * ## Sin endpoint de escritura
 *
 * Al diario sólo escriben oyentes de eventos de dominio, así que estas pruebas llaman al servicio directamente. No es un
 * atajo: es que **no hay** camino HTTP que probar, y crearlo sería la puerta por la que el diario deja de ser auditable.
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

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $this->tarjeta = PaymentMethod::query()->where('code', 'CARD')->sole();

    $this->journal = app(RecordFinancialMovement::class);

    // Una sesión de caja REAL, y no un id inventado.
    //
    // Estas pruebas pasaban `posSessionId: 1` cuando `pos_sessions` no existía: la columna del diario nació sin clave
    // foránea porque el diario va antes que la sesión (D232). Al cerrarse la FK en el paso 6, el id ficticio dejó de
    // ser válido — y eso es exactamente lo que una FK existe para impedir: un asiento apuntando a una caja que no
    // existe saldría en el corte de un turno inexistente.
    $this->terminal = Terminal::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA1',
        'name' => 'Caja 1',
    ]);

    $this->session = PosSession::create([
        'branch_id' => $this->branch->id,
        'terminal_id' => $this->terminal->id,
        'series' => 'A',
        'folio' => 1,
        'opening_float' => '0.00',
        'opened_by_membership_id' => $this->membership->id,
        'opened_at' => CarbonImmutable::now(),
    ]);

    /** Asienta un movimiento con lo mínimo, para no repetir seis argumentos en cada prueba. */
    $this->asentar = fn (
        FinancialMovementType $type,
        string $amount,
        string $sourceUlid = '01M0DIARIO000000000000001A',
        ?PaymentMethod $method = null,
        ?int $sessionId = null,
    ): FinancialMovement => $this->journal->record(
        branchId: $this->branch->id,
        type: $type,
        amount: $amount,
        sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosAccount',
        sourceUlid: $sourceUlid,
        actorMembershipId: $this->membership->id,
        // Sin sesión explícita se usa la de la prueba: los tipos que la exigen la necesitan de verdad, y los que no
        // la ignoran.
        posSessionId: $sessionId ?? (int) $this->session->id,
        paymentMethod: $method,
    );
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Inmutabilidad
// ---------------------------------------------------------------------------

it('un asiento no se edita ni se borra, ni por el query builder', function () {
    $movimiento = ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    expect(fn () => $movimiento->update(['amount' => '999.00']))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $movimiento->delete())->toThrow(ImmutableRecordException::class);

    // Y por el query builder, que NO dispara eventos de modelo y sería la puerta más ancha si sólo se escucharan
    // eventos.
    expect(fn () => FinancialMovement::query()->whereKey($movimiento->id)->update(['amount' => '999.00']))
        ->toThrow(ImmutableRecordException::class)
        ->and(fn () => FinancialMovement::query()->whereKey($movimiento->id)->delete())
        ->toThrow(ImmutableRecordException::class);

    expect($movimiento->refresh()->amount)->toBe('250.00');
});

it('el asiento trae su fecha ya casteada', function () {
    $movimiento = ($this->asentar)(FinancialMovementType::Payment, '100.00', method: $this->efectivo);

    // Con `$timestamps` apagado por el trait, Eloquent deja de castear `created_at` y vuelve como cadena: cualquier
    // `->toIso8601String()` reventaría. Es el defecto que apareció en la Iteración 3 y por eso el cast va a mano.
    expect($movimiento->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($movimiento->occurred_at)->toBeInstanceOf(CarbonImmutable::class);
});

// ---------------------------------------------------------------------------
// Idempotencia
// ---------------------------------------------------------------------------

it('el mismo documento y tipo no se asienta dos veces', function () {
    $primero = ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    // Segunda pasada: es lo que ocurre al re-despachar un evento para reparar un fallo de oyente.
    $segundo = ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    expect($segundo->id)->toBe($primero->id)
        ->and(FinancialMovement::query()->count())->toBe(1);
});

it('un mismo documento SÍ asienta tipos distintos', function () {
    // Una cuenta pagada produce su venta, su pago y su propina. La llave de idempotencia es (documento, TIPO)
    // justamente para que los tres convivan.
    ($this->asentar)(FinancialMovementType::Sale, '250.00');
    ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);
    ($this->asentar)(FinancialMovementType::Tip, '30.00', method: $this->efectivo);

    expect(FinancialMovement::query()->count())->toBe(3);
});

it('dos pagos de la misma cuenta se asientan por separado', function () {
    // Una cuenta partida entre efectivo y tarjeta produce DOS pagos. Cada línea de pago es su propio documento origen,
    // y sin eso la llave de idempotencia impediría el segundo — que es la nota de diseño escrita en la migración.
    ($this->asentar)(FinancialMovementType::Payment, '150.00', '01M0PAGO0000000000000001AA', $this->efectivo);
    ($this->asentar)(FinancialMovementType::Payment, '100.00', '01M0PAGO0000000000000002AA', $this->tarjeta);

    expect(FinancialMovement::query()->where('type', 'payment')->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// La bandera del cajón
// ---------------------------------------------------------------------------

it('la bandera del cajón se copia del método y no se relee', function () {
    $enEfectivo = ($this->asentar)(FinancialMovementType::Payment, '250.00', '01M0PAGO0000000000000001AA', $this->efectivo);
    $conTarjeta = ($this->asentar)(FinancialMovementType::Payment, '250.00', '01M0PAGO0000000000000002AA', $this->tarjeta);

    expect($enEfectivo->affects_cash_drawer)->toBeTrue()
        ->and($conTarjeta->affects_cash_drawer)->toBeFalse();

    // El método propio del negocio cambia de comportamiento: pasa a afectar el cajón.
    $vales = PaymentMethod::create([
        'code' => 'VALES',
        'name' => 'Vales',
        'kind' => App\Modules\Finance\Domain\Enums\PaymentMethodKind::Custom,
        'affects_cash_drawer' => false,
        'requires_reference' => false,
        'allows_change' => false,
    ]);

    $conVales = ($this->asentar)(FinancialMovementType::Payment, '80.00', '01M0PAGO0000000000000003AA', $vales);

    expect($conVales->affects_cash_drawer)->toBeFalse();

    $vales->update(['affects_cash_drawer' => true]);

    // EL ASIENTO DE AYER NO CAMBIA. Es la razón de copiar en lugar de leer: si el corte releyera la configuración del
    // método, cambiarla hoy movería los cortes de todos los días anteriores.
    expect($conVales->refresh()->affects_cash_drawer)->toBeFalse();
});

it('lo que ocurre en la caja mueve la caja aunque no tenga método', function () {
    // El fondo de apertura, el retiro, la liquidación de propina y la diferencia de corte no son cobros y no tienen
    // método de pago. Y sin embargo mueven el efectivo: son operaciones de caja por naturaleza.
    foreach ([
        FinancialMovementType::OpeningFloat,
        FinancialMovementType::Withdrawal,
        FinancialMovementType::CountDifference,
    ] as $indice => $tipo) {
        // El monto va CON el signo natural del tipo. Antes esta prueba mandaba 500 en positivo para los tres, y el
        // diario lo aceptaba: desde el paso 10 lo rechaza, porque un retiro en positivo hace que el arqueo cuadre al
        // revés. La prueba pasaba datos que la producción nunca produce.
        $monto = $tipo->naturalSign() < 0 ? '-500.00' : '500.00';

        $movimiento = ($this->asentar)($tipo, $monto, sprintf('01M0CAJA00000000000000%03dA', $indice));

        expect($movimiento->affects_cash_drawer)->toBeTrue("El tipo {$tipo->value} debería mover la caja");
    }

    // Y una venta sin método NO la mueve: la venta es el importe, el pago es el dinero. Confundirlos duplicaría el
    // efectivo esperado de cada corte.
    expect(($this->asentar)(FinancialMovementType::Sale, '250.00', '01M0VENTA000000000000001AA')->affects_cash_drawer)
        ->toBeFalse();
});

// ---------------------------------------------------------------------------
// Los invariantes
// ---------------------------------------------------------------------------

it('no se asienta un movimiento en cero', function () {
    // Si no hubo dinero, no hubo hecho. Y un corte que cuadra no asienta una diferencia de cero: simplemente no asienta
    // nada.
    expect(fn () => ($this->asentar)(FinancialMovementType::Payment, '0.00', method: $this->efectivo))
        ->toThrow(FinancialMovementInvariantException::class);

    // Tampoco un monto que REDONDEA a cero: medio centavo no es dinero.
    expect(fn () => ($this->asentar)(FinancialMovementType::Payment, '0.004', method: $this->efectivo))
        ->toThrow(FinancialMovementInvariantException::class);
});

it('lo que pertenece a una sesión no se asienta sin ella', function () {
    // Se llama al servicio DIRECTO y no al ayudante, porque el ayudante rellena la sesión de la prueba cuando no se le
    // pasa ninguna — y aquí lo que se prueba es justamente la ausencia. Mi primera versión pasaba `sessionId: null` al
    // ayudante y el `??` lo sustituía por la sesión real, así que la prueba no probaba nada y lo dijo al fallar.
    // El monto lleva el signo natural del tipo: un depósito SALE de la caja hacia el banco. El diario lo exige desde
    // el paso 10.
    $sinSesion = fn (FinancialMovementType $type, string $ulid): FinancialMovement => $this->journal->record(
        branchId: $this->branch->id,
        type: $type,
        amount: $type->naturalSign() < 0 ? '-250.00' : '250.00',
        sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosAccount',
        sourceUlid: $ulid,
        actorMembershipId: $this->membership->id,
        posSessionId: null,
    );

    // §6.3: toda venta, pago, retiro y cancelación pertenece a una sesión. Sin ella el arqueo no puede atribuirlo a
    // ningún turno y el corte de ese día quedaría corto.
    expect(fn () => $sinSesion(FinancialMovementType::Payment, '01M0SINSESION000000001AAA'))
        ->toThrow(FinancialMovementInvariantException::class);

    // Y lo que NO pertenece a una sesión sí se asienta sin ella: un gasto pagado por transferencia desde la oficina y
    // un depósito bancario existen sin turno abierto.
    expect($sinSesion(FinancialMovementType::Deposit, '01M0DEPOSITO0000000001AAA'))
        ->toBeInstanceOf(FinancialMovement::class);
});

it('el diario RECHAZA un asiento con el signo contrario a su tipo', function () {
    // El encabezado del enum avisaba desde el paso 4 de que éste es «el error más fácil de cometer», y en el paso 10 lo
    // cometí: asenté el cambio de un cobro en positivo, dando por hecho que el servicio aplicaría el signo. No lo
    // aplica —la firma pide el monto CON signo— y el resultado era un cajón que cuadraba al revés sin que nada fallara.
    //
    // Se comprueba en lugar de corregirse en silencio: aplicar el signo aquí escondería que quien asienta entendió mal
    // el sentido del movimiento, y hay casos donde eso importa.
    expect(fn () => ($this->asentar)(FinancialMovementType::Change, '100.00', '01M0SIGNO0000000000001AAA'))
        ->toThrow(FinancialMovementInvariantException::class);

    expect(fn () => ($this->asentar)(FinancialMovementType::Payment, '-100.00', '01M0SIGNO0000000000002AAA'))
        ->toThrow(FinancialMovementInvariantException::class);

    // Y con el signo correcto, pasa.
    expect(($this->asentar)(FinancialMovementType::Change, '-100.00', '01M0SIGNO0000000000003AAA'))
        ->toBeInstanceOf(FinancialMovement::class);
});

it('una REVERSA lleva el signo contrario al natural de su tipo', function () {
    // Una reversa conserva el TIPO del movimiento que corrige y toma el signo contrario: revertir un pago de 250 es un
    // pago de −250, no un asiento de tipo «reversa». Es lo que permite que «cuánto se pagó con tarjeta» se conteste
    // sumando los asientos de pago, con las correcciones incluidas.
    //
    // Mi primera versión de la comprobación de signo rechazaba justamente esto, y el patrón que ya existía era el
    // correcto.
    $original = ($this->asentar)(FinancialMovementType::Payment, '250.00', '01M0REVERSA000000001AAAAA');

    $reversa = $this->journal->record(
        branchId: $this->branch->id,
        type: FinancialMovementType::Payment,
        amount: '-250.00',
        sourceType: 'App\Modules\Pos\Infrastructure\Models\PosPayment',
        sourceUlid: '01M0REVERSA000000002AAAAA',
        actorMembershipId: $this->membership->id,
        posSessionId: $this->session->id,
        reverses: $original,
    );

    expect((string) $reversa->amount)->toBe('-250.00');
    expect((int) $reversa->reverses_movement_id)->toBe($original->id);

    // Y una «reversa» con el mismo signo que el original no lo es: sería duplicar el movimiento, no corregirlo.
    expect(fn () => $this->journal->record(
        branchId: $this->branch->id,
        type: FinancialMovementType::Payment,
        amount: '250.00',
        sourceType: 'App\Modules\Pos\Infrastructure\Models\PosPayment',
        sourceUlid: '01M0REVERSA000000003AAAAA',
        actorMembershipId: $this->membership->id,
        posSessionId: $this->session->id,
        reverses: $original,
    ))->toThrow(FinancialMovementInvariantException::class);
});

it('el signo natural de cada tipo está declarado', function () {
    // Existe para que quien asienta no lo tenga que recordar: poner un retiro en positivo dejaría el arqueo cuadrando
    // al revés, y es el error más fácil de cometer.
    expect(FinancialMovementType::Payment->naturalSign())->toBe(1)
        ->and(FinancialMovementType::Withdrawal->naturalSign())->toBe(-1)
        ->and(FinancialMovementType::Change->naturalSign())->toBe(-1)
        // El descuento RESTA de lo vendido: es el importe que no se cobró. En positivo, un descuento aumentaría la
        // venta.
        ->and(FinancialMovementType::Discount->naturalSign())->toBe(-1)
        ->and(FinancialMovementType::Courtesy->naturalSign())->toBe(-1)
        // Los que dependen del caso no tienen sentido propio.
        ->and(FinancialMovementType::CountDifference->naturalSign())->toBe(0)
        ->and(FinancialMovementType::Reversal->naturalSign())->toBe(0);
});

// ---------------------------------------------------------------------------
// La reversa
// ---------------------------------------------------------------------------

it('una corrección es una reversa enlazada a su original', function () {
    $original = ($this->asentar)(FinancialMovementType::Payment, '250.00', '01M0PAGO0000000000000001AA', $this->efectivo);

    $reversa = $this->journal->record(
        branchId: $this->branch->id,

        // Se asienta con el MISMO tipo y en negativo, no con tipo `reversal`: así el corte la suma donde toca y el
        // efectivo esperado baja. Su naturaleza de corrección la lleva el enlace.
        type: FinancialMovementType::Payment,
        amount: '-250.00',
        sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosPayment',
        sourceUlid: '01M0REVERSA00000000001AAA',
        actorMembershipId: $this->membership->id,
        posSessionId: (int) $this->session->id,
        paymentMethod: $this->efectivo,
        reverses: $original,
    );

    expect($reversa->isReversal())->toBeTrue()
        ->and($reversa->reverses_movement_id)->toBe($original->id)
        // El original NO se marca ni se toca: la historia dice «esto se cobró y luego se devolvió», no «esto nunca
        // pasó».
        ->and($original->refresh()->isReversal())->toBeFalse();

    // Y el neto es cero, que es lo que el arqueo necesita ver.
    $neto = FinancialMovement::query()->where('type', 'payment')->sum('amount');

    expect((float) $neto)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Lectura por API
// ---------------------------------------------------------------------------

it('el diario se lee del presente al pasado, y sólo se lee', function () {
    ($this->asentar)(FinancialMovementType::Sale, '250.00', '01M0VENTA000000000000001AA');
    ($this->asentar)(FinancialMovementType::Payment, '250.00', '01M0PAGO0000000000000001AA', $this->efectivo);

    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/financial-movements')
        ->assertOk()
        ->json('data');

    expect($datos)->toHaveCount(2)
        ->and($datos[0]['type_label'])->toBeString()
        // El documento origen viaja por ULID y con el nombre corto de su clase: la llave interna no se expone (§7).
        ->and($datos[0]['source']['ulid'])->toHaveLength(26)
        ->and($datos[0]['source']['type'])->toBe('PosAccount');

    // No hay endpoint de escritura, y eso es parte del diseño: al diario sólo escriben oyentes de eventos.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/financial-movements', [])
        ->assertStatus(405);
});

it('el diario filtra por lo que mueve el cajón', function () {
    ($this->asentar)(FinancialMovementType::Payment, '150.00', '01M0PAGO0000000000000001AA', $this->efectivo);
    ($this->asentar)(FinancialMovementType::Payment, '100.00', '01M0PAGO0000000000000002AA', $this->tarjeta);

    app(TenantContext::class)->forget();

    // Es la mitad del arqueo. Sin este filtro habría que traer todo el diario y sumar en el cliente, que es donde se
    // cuelan los errores de redondeo (D134).
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/financial-movements?cash_only=1')
        ->assertOk()
        ->json('data');

    expect($datos)->toHaveCount(1)
        ->and($datos[0]['amount'])->toBe('150.00');
});

// ---------------------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------------------

it('el diario de un negocio no se ve desde otro', function () {
    ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    app(TenantContext::class)->forget();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/financial-movements')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Categorías de gasto
// ---------------------------------------------------------------------------

it('el alta de un negocio siembra las categorías de gasto', function () {
    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/expense-categories')
        ->assertOk()
        ->json('data');

    // Se siembran, a diferencia de los motivos de merma que nacen vacíos (D225): los gastos de una cocina son los
    // mismos en todas partes, y una lista vacía sólo conseguiría que el primer gasto urgente acabara en una categoría
    // inventada al vuelo.
    expect(count($datos))->toBeGreaterThanOrEqual(8);

    foreach ($datos as $categoria) {
        expect($categoria['is_system'])->toBeTrue()
            ->and($categoria['can_be_deleted'])->toBeFalse();
    }
});

it('una categoría del sistema se renombra pero no se borra', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $categoria = ExpenseCategory::query()->where('name', 'Servicios (luz, agua, gas)')->sole();
    app(TenantContext::class)->forget();

    // El nombre SÍ: es una etiqueta de reporte que el negocio ajusta a su vocabulario —«Luz» o «CFE»—, no la referencia
    // con la que el diario agrupa el dinero. Aquí está la asimetría con los métodos de pago, y es deliberada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/expense-categories/{$categoria->ulid}", ['name' => 'Luz, agua y gas'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Luz, agua y gas');

    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => $categoria->fresh()->delete())
        ->toThrow(App\Modules\Finance\Domain\Exceptions\ExpenseCategoryInvariantException::class);
});

it('dos categorías no comparten nombre', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/expense-categories', ['name' => 'Renta'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

it('el negocio agrega su propia categoría, y la puede dar de baja', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/expense-categories', ['name' => 'Música en vivo'])
        ->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.can_be_deleted', true)
        ->json('data.ulid');

    // Dar de baja y volver a activar: el mismo endpoint leído en dos direcciones. Una categoría con gastos que la
    // citan se desactiva en lugar de borrarse, para que el histórico siga pudiendo decir en qué se gastó.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/expense-categories/{$ulid}/toggle")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/expense-categories/{$ulid}/toggle")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

// ---------------------------------------------------------------------------
// Garantía estructural
// ---------------------------------------------------------------------------

it('la base impone la llave de idempotencia', function () {
    ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    // Sin pasar por el servicio (D218): la llave única puede estar en el diseño y no en la base, y probarla sólo a
    // través del servicio comprobaría el `try/catch` en lugar de la garantía.
    expect(fn () => FinancialMovement::create([
        'branch_id' => $this->branch->id,
        'type' => FinancialMovementType::Payment,
        'pos_session_id' => $this->session->id,
        'payment_method_id' => $this->efectivo->id,
        'affects_cash_drawer' => true,
        'amount' => '999.00',
        'source_type' => 'App\\Modules\\Pos\\Infrastructure\\Models\\PosAccount',
        'source_ulid' => '01M0DIARIO000000000000001A',
        'actor_membership_id' => $this->membership->id,
        'occurred_at' => CarbonImmutable::now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('un asiento exige sucursal (la base) y actor salvo el automático (el servicio)', function () {
    // La SUCURSAL sigue siendo NOT NULL en la base: sin ella un asiento no se puede reportar. Se comprueba en la base
    // porque ningún camino de escritura debe poder saltárselo.
    expect(fn () => FinancialMovement::create([
        'type' => FinancialMovementType::Sale,
        'amount' => '100.00',
        'source_type' => 'X',
        'source_ulid' => '01M0SINSUCURSAL000000001A',
        'actor_membership_id' => $this->membership->id,
        'occurred_at' => CarbonImmutable::now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);

    // El ACTOR lo exige ahora el SERVICIO, no la base: la columna es nullable desde la Iteración 8 porque la venta en
    // línea no tiene actor (ADR-010), así que el candado del actor del POS vive en `record()`. Un asiento del POS sin
    // actor —que ya no rechaza la base— sigue sin poder escribirse.
    expect(fn () => $this->journal->record(
        branchId: $this->branch->id,
        type: FinancialMovementType::Sale,
        amount: '100.00',
        sourceType: 'App\\Modules\\Pos\\Infrastructure\\Models\\PosAccount',
        sourceUlid: '01M0SINACTOR00000000001AA',
        actorMembershipId: null,
        posSessionId: (int) $this->session->id,
    ))->toThrow(FinancialMovementInvariantException::class);
});

it('la venta en línea es el único asiento sin actor ni sesión de caja (ADR-010)', function () {
    // El asiento automático del e-commerce: lo origina el cliente por pasarela, no el personal en un cajón. Suma como
    // venta, pero con su tipo propio `OnlineSale`, sin actor y sin sesión —los dos candados que sí rigen al mostrador—.
    $mov = $this->journal->record(
        branchId: $this->branch->id,
        type: FinancialMovementType::OnlineSale,
        amount: '250.00',
        sourceType: 'App\\Modules\\Ecommerce\\Infrastructure\\Models\\Order',
        sourceUlid: '01M0ONLINE0000000000001AA',
        actorMembershipId: null,
    );

    expect($mov->type)->toBe(FinancialMovementType::OnlineSale)
        ->and($mov->actor_membership_id)->toBeNull()
        ->and($mov->pos_session_id)->toBeNull()
        ->and($mov->amount)->toBe('250.00');
});

it('el actor de un asiento no se puede borrar', function () {
    ($this->asentar)(FinancialMovementType::Payment, '250.00', method: $this->efectivo);

    // RESTRICT: borrar a la persona dejaría asientos que no pueden decir quién los hizo, y eso es justo lo que un
    // diario auditable no admite. La membresía se da de baja por estado, no se borra.
    expect(fn () => TenantMembership::query()->whereKey($this->membership->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});

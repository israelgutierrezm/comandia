<?php

declare(strict_types=1);

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Pos\Infrastructure\Models\PosSessionWithdrawal;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Schema;

/**
 * LA SESIÓN DE CAJA (§6.3)
 *
 * ## Es la primera tabla del POS porque nada se puede cobrar sin ella
 *
 * §6.3 no admite matices: «sin sesión abierta no hay cobro», y «toda venta, pago, retiro y cancelación pertenece a una
 * sesión». Eso es lo que hace que el arqueo signifique algo.
 *
 * ## Lo que estas pruebas fijan
 *
 * Una sola sesión abierta por terminal, garantizada por la BASE. El fondo y los retiros asentados en el diario por
 * EVENTOS del kernel, sin que ninguno de los dos módulos conozca al otro. Y que un fallo al asentar **no puede tumbar la
 * operación de caja** — la lección de D220 aplicada desde el diseño.
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

    $this->terminal = Terminal::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA1',
        'name' => 'Caja 1',
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $this->tarjeta = PaymentMethod::query()->where('code', 'CARD')->sole();

    // El PIN del propietario, para las operaciones que lo exigen. Sin PIN sembrado el diálogo de autorización es un
    // callejón sin salida, que es lo que D224 encontró en el negocio de demostración.
    app(ManageMembershipPin::class)->set($this->membership->fresh(), '4321');

    app(TenantContext::class)->forget();

    /** Abre una caja y devuelve su ULID. */
    $this->abrir = fn (string $fondo = '500.00'): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => $fondo,
        ])
        ->assertCreated()
        ->json('data.ulid');

    /**
     * Declara el cierre y cierra: dos pasos que casi todas las pruebas necesitan juntos.
     *
     * Va como closure del test y NO como función global del archivo: `actingAsSpa` es `protected` en el `TestCase`, así
     * que una función global no lo alcanza. Y de paso se evita el `Cannot redeclare` que un ayudante global con nombre
     * repetido provoca en toda la suite (D191).
     */
    $this->cerrarTurno = function (string $sessionUlid): void {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-sessions/{$sessionUlid}/declarations", [
                'moment' => 'close',
                'declarations' => [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => '500.00']],
            ])
            ->assertOk();

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-sessions/{$sessionUlid}/close")
            ->assertOk();
    };

    /** Un token de PIN válido para un permiso. */
    $this->autorizacion = fn (string $permiso): string => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): string => app(PinAuthorizationService::class)
            ->grant('P001', '4321', $permiso)
            ->token,
    );
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Abrir
// ---------------------------------------------------------------------------

it('abre una caja con su fondo y su folio', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening_float', '500.00')
        ->assertJsonPath('data.terminal.code', 'CAJA1');

    // El folio sin huecos, por (tenant, sucursal, tipo, serie). Un hueco en la secuencia de cortes es lo primero que un
    // auditor pregunta.
    expect($respuesta->json('data.folio'))->toBe('A-1')
        // Las transiciones las decide el servidor: se puede precortar o cerrar directamente, porque el precorte es
        // configurable y un negocio que no lo usa no debe pasar por un paso vacío.
        ->and($respuesta->json('data.allowed_next'))->toBe(['precounted', 'closed']);
});

it('el fondo se asienta en el diario, por un evento del kernel', function () {
    ($this->abrir)('500.00');

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::OpeningFloat->value)->sole();

    expect($asiento->amount)->toBe('500.00')
        // Lo que ocurre en la caja mueve la caja, aunque no haya método de pago: el fondo no es un cobro.
        ->and($asiento->affects_cash_drawer)->toBeTrue()
        ->and($asiento->pos_session_id)->not->toBeNull();
});

it('un fondo de cero no asienta nada, y la caja abre igual', function () {
    // Cero es legítimo —una caja que abre sin cambio— y el diario rechaza asientos en cero con razón: si no hubo dinero,
    // no hubo hecho. Lo que no puede pasar es que eso impida abrir.
    ($this->abrir)('0.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(PosSession::query()->count())->toBe(1)
        ->and(FinancialMovement::query()->count())->toBe(0);
});

it('una terminal no tiene dos turnos abiertos, y lo impone la BASE', function () {
    ($this->abrir)();

    // Por la API, con su mensaje de dominio: 409 y no 422 — no hay nada en el cuerpo que corregir, lo que no encaja es
    // el estado del negocio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '300.00',
        ])
        ->assertStatus(409);

    // Y SIN pasar por la aplicación (D218): la columna generada con índice único puede estar en el diseño y no en la
    // base, y probarlo por la API sólo comprobaría la comprobación previa del servicio.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => PosSession::create([
        'branch_id' => $this->branch->id,
        'terminal_id' => $this->terminal->id,
        'series' => 'A',
        'folio' => 999,
        'opening_float' => '100.00',
        'opened_by_membership_id' => $this->membership->id,
        'opened_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('la caja se puede volver a abrir después de cerrarla', function () {
    $primera = ($this->abrir)();

    ($this->cerrarTurno)($primera);

    // El índice único es sobre la columna generada, que vale NULL cuando la sesión está cerrada: las cerradas conviven
    // y sólo las abiertas se excluyen entre sí.
    $segunda = ($this->abrir)('300.00');

    expect($segunda)->not->toBe($primera);

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosSession::query()->count())->toBe(2);
});

it('el turno abierto de una terminal se consulta, y sin turno devuelve nulo', function () {
    // «Sin turno» es una respuesta legítima y no un error: un 404 haría que la pantalla de caja tratara el caso normal
    // como fallo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/terminals/{$this->terminal->ulid}/current-session")
        ->assertOk()
        ->assertJsonPath('data', null);

    ($this->abrir)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/terminals/{$this->terminal->ulid}/current-session")
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

// ---------------------------------------------------------------------------
// Declarar y precortar
// ---------------------------------------------------------------------------

it('el precorte sella la sesión y la caja sigue operando', function () {
    $ulid = ($this->abrir)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/declarations", [
            'moment' => 'precount',
            'declarations' => [
                ['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => '1250.50'],
                ['payment_method_ulid' => $this->tarjeta->ulid, 'declared_amount' => '0.00'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'precounted')
        // Sigue abierta: el precorte no es un estado terminal, es un sello de que ocurrió. Su valor está en que se hace
        // ANTES de cerrar, cuando todavía puede aparecer un pago más.
        ->assertJsonPath('data.is_open', true)
        ->assertJsonCount(2, 'data.declarations');

    // Cero es una declaración legítima: «de tarjeta no entró nada» es información, y omitir el método dejaría la duda de
    // si alguien lo contó.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-sessions/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.precounted_by.employee_code', 'P001');
});

it('volver a declarar el mismo método corrige, no duplica', function () {
    $ulid = ($this->abrir)();

    foreach (['1000.00', '1250.00'] as $monto) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-sessions/{$ulid}/declarations", [
                'moment' => 'close',
                'declarations' => [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => $monto]],
            ])
            ->assertOk();
    }

    // Mientras el arqueo no ha ocurrido, corregir un dedazo de conteo no borra evidencia: está contando otra vez, que es
    // lo que se espera de alguien que cuenta dinero.
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-sessions/{$ulid}")
        ->assertOk()
        ->json('data.declarations');

    expect($datos)->toHaveCount(1)
        ->and($datos[0]['declared_amount'])->toBe('1250.00');
});

// ---------------------------------------------------------------------------
// Retirar
// ---------------------------------------------------------------------------

it('el retiro exige PIN SIEMPRE, sin umbral', function () {
    $ulid = ($this->abrir)();

    // Sin autorización: 409 con el permiso que hace falta. Un retiro pequeño no es un vaso roto —§6.3 lo pone en la
    // lista de acciones sensibles sin excepción de monto— así que un umbral aquí sería una puerta con altura mínima.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/withdrawals", [
            'amount' => '50.00',
            'reason' => 'Garrafones de agua',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'pos.sessions.withdraw');
});

it('el retiro firmado se registra y sale del diario en negativo', function () {
    $ulid = ($this->abrir)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/withdrawals", [
            'amount' => '150.00',
            'reason' => 'Garrafones de agua',
            'authorization_token' => ($this->autorizacion)('pos.sessions.withdraw'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.amount', '150.00');

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Withdrawal->value)->sole();

    // EN NEGATIVO: el retiro sale del cajón. En positivo dejaría el arqueo cuadrando al revés, y es el error más fácil
    // de cometer.
    expect($asiento->amount)->toBe('-150.00')
        ->and($asiento->affects_cash_drawer)->toBeTrue();

    // Y el retiro quedó con su autorizador, distinto de quien lo hizo cuando aplica.
    expect(PosSessionWithdrawal::query()->sole()->authorized_by_membership_id)->not->toBeNull();
});

it('un retiro no se edita ni se borra', function () {
    $ulid = ($this->abrir)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/withdrawals", [
            'amount' => '150.00',
            'reason' => 'Garrafones',
            'authorization_token' => ($this->autorizacion)('pos.sessions.withdraw'),
        ])
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $retiro = PosSessionWithdrawal::query()->sole();

    // Append-only: un retiro es dinero que salió. Si se pudiera editar, el arqueo dejaría de ser evidencia.
    expect(fn () => $retiro->update(['amount' => '10.00']))->toThrow(ImmutableRecordException::class)
        ->and(fn () => $retiro->delete())->toThrow(ImmutableRecordException::class);
});

it('el motivo del retiro es obligatorio', function () {
    $ulid = ($this->abrir)();

    // Mismo argumento que en las mermas (D27): un retiro sin motivo es dinero que salió del cajón y nadie puede
    // explicar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/withdrawals", ['amount' => '50.00'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);
});

// ---------------------------------------------------------------------------
// Cerrar
// ---------------------------------------------------------------------------

it('no se cierra una caja sin declarar lo que hay', function () {
    $ulid = ($this->abrir)();

    // Sin la declaración no hay arqueo posible: el corte compara lo declarado contra lo esperado, y quedaría un turno
    // que sólo dice que terminó.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-sessions/{$ulid}/close")
        ->assertStatus(409);
});

it('una caja cerrada no admite nada más', function () {
    $ulid = ($this->abrir)();
    ($this->cerrarTurno)($ulid);

    foreach ([
        ['POST', "/api/v1/pos-sessions/{$ulid}/close", []],
        ['POST', "/api/v1/pos-sessions/{$ulid}/withdrawals", ['amount' => '10.00', 'reason' => 'Algo']],
        ['POST', "/api/v1/pos-sessions/{$ulid}/declarations", [
            'moment' => 'close',
            'declarations' => [['payment_method_ulid' => $this->efectivo->ulid, 'declared_amount' => '1.00']],
        ]],
    ] as [$metodo, $ruta, $cuerpo]) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->json($metodo, $ruta, $cuerpo)
            ->assertStatus(409);
    }
});

// ---------------------------------------------------------------------------
// Lo que NO puede pasar
// ---------------------------------------------------------------------------

it('un fallo al asentar en el diario NO impide abrir la caja', function () {
    // Se rompe el diario DE VERDAD, renombrando su tabla: cualquier `INSERT` falla como fallaría con la base caída.
    //
    // No se sustituye el servicio porque `RecordFinancialMovement` es `final readonly` —y debe serlo: es la única puerta
    // de escritura del diario—. Un doble de prueba exigiría abrirla, o sea empeorar el diseño para poder probarlo.
    //
    // Y lo que se prueba es la lección de D220: en la Iteración 3, un oyente que lanzaba hizo que una confirmación de
    // compra respondiera 422 con la mercancía ya en el kardex, y quien confirmó creyó que no había pasado nada. Aquí
    // sería peor — «no se pudo abrir la caja» con la caja abierta deja al cajero intentándolo otra vez, y el índice
    // único le diría que ya hay un turno sin que él lo vea en ninguna pantalla.
    Schema::rename('financial_movements', 'financial_movements_caido');

    try {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', [
                'terminal_ulid' => $this->terminal->ulid,
                'opening_float' => '500.00',
            ])
            ->assertCreated();
    } finally {
        Schema::rename('financial_movements_caido', 'financial_movements');
    }

    app(TenantContext::class)->set($this->tenant->id);

    // La caja quedó abierta y el diario vacío. El estado incompleto es reparable re-despachando el evento, porque el
    // asiento es idempotente por (documento, tipo).
    expect(PosSession::query()->count())->toBe(1)
        ->and(FinancialMovement::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------------------

it('las cajas de un negocio no se ven desde otro', function () {
    // La caja se abre POR MODELO y no por API: alternar de usuario autenticado dentro de una misma prueba deja la
    // segunda sesión sin autenticar —recibí 401—, así que el estado de partida se prepara sin HTTP. Es la misma
    // limitación del helper que encontré en el paso 2.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => PosSession::create([
        'branch_id' => $this->branch->id,
        'terminal_id' => $this->terminal->id,
        'series' => 'A',
        'folio' => 1,
        'opening_float' => '500.00',
        'opened_by_membership_id' => $this->membership->id,
        'opened_at' => now(),
    ]));

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
        ->getJson('/api/v1/pos-sessions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

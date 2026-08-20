<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Printing\Domain\Enums\PrintJobKind;
use App\Modules\Printing\Domain\Enums\PrintJobStatus;
use App\Modules\Printing\Infrastructure\Models\PrintAgent;
use App\Modules\Printing\Infrastructure\Models\PrintJob;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * IMPRESIÓN: EL CONTRATO DEL AGENTE Y EL CAJÓN (§9, paso 9)
 *
 * ## Las tres cosas que estas pruebas existen para demostrar
 *
 * **Comandar encola papel, y un fallo de impresión NO tumba la venta.** Es la lección de D220 puesta desde el diseño: si
 * una impresora mal configurada pudiera hacer fallar la petición, una configuración incompleta impediría vender.
 *
 * **Reclamar es exclusivo.** Dos agentes en la misma sucursal no se llevan el mismo trabajo, o la cocina prepara la
 * comida dos veces.
 *
 * **Reportar es idempotente.** El agente vive en una computadora con una red que se cae: reporta, no recibe respuesta y
 * vuelve a reportar. La segunda vez no puede contar otro intento ni fallar.
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

    $almacen = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->impresoraCocina = Printer::create([
        'branch_id' => $this->branch->id,
        'code' => 'COCINA',
        'name' => 'Impresora de cocina',
        'connection' => PrinterConnection::Network,
        'target' => '192.168.1.50:9100',
        'paper_width' => 80,
        'supports_cash_drawer' => false,
    ]);

    $this->impresoraCaja = Printer::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA',
        'name' => 'Impresora de caja',
        'connection' => PrinterConnection::Usb,
        'target' => 'POS-80',
        'paper_width' => 80,
        'supports_cash_drawer' => true,
    ]);

    $this->cocina = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'printer_id' => $this->impresoraCocina->id,
        'code' => 'COCINA',
        'name' => 'Cocina',
    ]);

    // Un área SIN impresora: es lo que pasa cuando el negocio la dio de alta y no la configuró.
    $this->barraSinImpresora = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'BARRA',
        'name' => 'Barra',
    ]);

    $unidad = Unit::query()->where('code', 'pza')->sole();

    $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);
    $bebidas = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    $this->tacos = Article::create([
        'name' => 'Tacos de canasta',
        'category_id' => $alimentos->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '60.00',
        'is_available_in_pos' => true,
    ]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $bebidas->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '45.00',
        'is_available_in_pos' => true,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $alimentos->id,
        'preparation_area_id' => $this->cocina->id,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $bebidas->id,
        'preparation_area_id' => $this->barraSinImpresora->id,
    ]);

    app(TenantContext::class)->forget();

    /** Abre cuenta, captura una línea y la comanda. Devuelve la respuesta de comandar. */
    $this->venderYComandar = function (Article $articulo) {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra 1'])
            ->assertCreated()
            ->json('data.ulid');

        $captura = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $articulo->ulid, 'quantity' => '2']],
            ])
            ->assertCreated();

        $ordenes = $captura->json('data.orders');
        $orden = end($ordenes)['ulid'];

        return $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command");
    };

    /** Da de alta un agente y devuelve [modelo, token]. */
    $this->crearAgente = function (string $nombre = 'Tableta de la barra'): array {
        $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/print-agents', [
                'branch_ulid' => $this->branch->ulid,
                'name' => $nombre,
            ])
            ->assertCreated();

        app(TenantContext::class)->set($this->tenant->id);
        $agente = PrintAgent::query()->where('ulid', $respuesta->json('data.ulid'))->sole();
        app(TenantContext::class)->forget();

        return [$agente, $respuesta->json('data.token')];
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Encolar
// ---------------------------------------------------------------------------

it('comandar encola un trabajo para la impresora del área', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $trabajo = PrintJob::query()->sole();

    expect($trabajo->kind)->toBe(PrintJobKind::Ticket);
    expect($trabajo->status)->toBe(PrintJobStatus::Pending);
    expect((int) $trabajo->printer_id)->toBe($this->impresoraCocina->id);

    // El payload se congela al encolar: reimprimir vuelve a mandar el mismo papel.
    expect($trabajo->payload['items'][0]['name'])->toBe('Tacos de canasta');
    expect($trabajo->payload['area'])->toBe('Cocina');
    expect($trabajo->payload['version'])->toBe(1);
});

it('un área SIN impresora no encola nada, y la venta sigue', function () {
    // Es la lección de D220 puesta desde el diseño: si esto reventara, una configuración incompleta de impresoras
    // impediría VENDER, y §6.2 dice que el POS nunca se bloquea.
    //
    // La contrapartida honesta es que el papel no sale y nadie se entera en el momento; lo cubre la pantalla de
    // trabajos, no una excepción en la cara del cajero.
    ($this->venderYComandar)($this->cafe)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    expect(PrintJob::query()->count())->toBe(0);
});

it('el payload lleva el nombre CONGELADO, no el del catálogo de hoy', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $this->tacos->update(['name' => 'Tacos de suadero']);

    expect(PrintJob::query()->sole()->payload['items'][0]['name'])->toBe('Tacos de canasta');
});

// ---------------------------------------------------------------------------
// El contrato del agente
// ---------------------------------------------------------------------------

it('el agente reclama, imprime y reporta', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [$agente, $token] = ($this->crearAgente)();

    $trabajo = $this->actingAsPrintAgent($token)
        ->getJson('/api/v1/print-agent/jobs/next')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        // El destino y el ancho van con el trabajo: sin ellos el agente necesitaría un endpoint más por cada papel.
        ->assertJsonPath('data.0.printer.target', '192.168.1.50:9100')
        ->assertJsonPath('data.0.printer.paper_width', 80)
        ->assertJsonPath('data.0.status', 'claimed')
        ->json('data.0.ulid');

    $this->actingAsPrintAgent($token)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/printed")
        ->assertOk()
        ->assertJsonPath('data.status', 'printed')
        ->assertJsonPath('data.attempts', 1);

    app(TenantContext::class)->set($this->tenant->id);

    // «Visto hace un momento»: es lo que distingue un agente sano sin trabajos de uno muerto.
    expect($agente->refresh()->last_seen_at)->not->toBeNull();
});

it('reclamar es EXCLUSIVO: el segundo agente no se lleva lo mismo', function () {
    // Con dos agentes en la misma sucursal —una tableta y la computadora de la caja— sin exclusión los dos leerían los
    // mismos pendientes y la cocina recibiría cada comanda dos veces.
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $primero] = ($this->crearAgente)('Tableta');
    [, $segundo] = ($this->crearAgente)('Computadora de la caja');

    $this->actingAsPrintAgent($primero)->getJson('/api/v1/print-agent/jobs/next')->assertOk()->assertJsonCount(1, 'data');

    $this->actingAsPrintAgent($segundo)->getJson('/api/v1/print-agent/jobs/next')->assertOk()->assertJsonCount(0, 'data');
});

it('reportar es IDEMPOTENTE y no cuenta dos intentos', function () {
    // El agente vive en una computadora con una red que se cae: reporta, no recibe respuesta y vuelve a reportar. Sin
    // esto, tendría que llevar su propio registro de qué reportó — pedirle memoria a la parte menos confiable.
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $token] = ($this->crearAgente)();

    $trabajo = $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->json('data.0.ulid');

    $this->actingAsPrintAgent($token)->postJson("/api/v1/print-agent/jobs/{$trabajo}/printed")->assertOk();

    $this->actingAsPrintAgent($token)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/printed")
        ->assertOk()
        ->assertJsonPath('data.attempts', 1);
});

it('un agente no reporta un trabajo que no reclamó', function () {
    // Con dos agentes, el segundo podría marcar como impreso el papel del primero: la cocina no recibiría nada y el
    // sistema diría que sí.
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $primero] = ($this->crearAgente)('Tableta');
    [, $segundo] = ($this->crearAgente)('Computadora de la caja');

    $trabajo = $this->actingAsPrintAgent($primero)->getJson('/api/v1/print-agent/jobs/next')->json('data.0.ulid');

    $this->actingAsPrintAgent($segundo)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/printed")
        ->assertStatus(409);
});

it('reportar un fallo exige motivo y deja el trabajo visible', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $token] = ($this->crearAgente)();

    $trabajo = $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->json('data.0.ulid');

    // Sin motivo: quien mira la pantalla no sabría si poner papel, encender la impresora o revisar la red.
    $this->actingAsPrintAgent($token)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/failed")
        ->assertStatus(422);

    $this->actingAsPrintAgent($token)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/failed", ['error' => 'Sin papel'])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.last_error', 'Sin papel');

    // NO se reintenta solo: veinte intentos en un minuto acabarían en veinte comandas saliendo juntas cuando alguien
    // ponga papel, con platos repetidos que la cocina no puede distinguir.
    $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->assertOk()->assertJsonCount(0, 'data');
});

it('sin token válido, el agente no entra', function () {
    $this->getJson('/api/v1/print-agent/jobs/next')->assertStatus(401);

    $this->actingAsPrintAgent('un-token-inventado')->getJson('/api/v1/print-agent/jobs/next')->assertStatus(401);
});

it('un agente sólo ve los trabajos de SU sucursal y de SU negocio', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $ajeno = $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->postJson('/api/v1/print-agents', [
            'branch_ulid' => $otro['branch']->ulid,
            'name' => 'Agente ajeno',
        ])
        ->assertCreated()
        ->json('data.token');

    // El tenant sale del AGENTE, nunca de la petición (ADR-002): no hay parámetro por el que pedir lo ajeno.
    $this->actingAsPrintAgent($ajeno)->getJson('/api/v1/print-agent/jobs/next')->assertOk()->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// La pantalla de administración
// ---------------------------------------------------------------------------

it('lista los trabajos y reintenta uno fallido', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $token] = ($this->crearAgente)();

    $trabajo = $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->json('data.0.ulid');

    $this->actingAsPrintAgent($token)
        ->postJson("/api/v1/print-agent/jobs/{$trabajo}/failed", ['error' => 'Sin papel'])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/print-jobs?only_open=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'failed');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/print-jobs/{$trabajo}")
        ->assertOk()
        ->assertJsonPath('data.last_error', 'Sin papel');

    // Alguien puso papel y lo vuelve a mandar. `attempts` NO se reinicia: es la señal de que este papel lleva rato sin
    // salir.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/print-jobs/{$trabajo}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempts', 1);

    // Y ahora sí vuelve a estar disponible para el agente.
    $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->assertJsonCount(1, 'data');
});

it('un trabajo ya impreso no se puede devolver a la cola', function () {
    ($this->venderYComandar)($this->tacos)->assertCreated();

    [, $token] = ($this->crearAgente)();

    $trabajo = $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->json('data.0.ulid');
    $this->actingAsPrintAgent($token)->postJson("/api/v1/print-agent/jobs/{$trabajo}/printed")->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/print-jobs/{$trabajo}/retry")
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// Agentes
// ---------------------------------------------------------------------------

it('el token se muestra una vez y se puede rotar', function () {
    [$agente, $token] = ($this->crearAgente)();

    // No sale en la lista: publicarlo lo dejaría en cualquier caché del navegador y en cualquier registro de red.
    $lista = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/print-agents')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(json_encode($lista->json()))->not->toContain($token);

    $nuevo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/print-agents/{$agente->ulid}/rotate-token")
        ->assertOk()
        ->json('data.token');

    expect($nuevo)->not->toBe($token);

    // El anterior deja de servir EN EL MOMENTO: el valor de rotar es cortar el acceso ya.
    $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->assertStatus(401);
    $this->actingAsPrintAgent($nuevo)->getJson('/api/v1/print-agent/jobs/next')->assertOk();
});

it('un agente archivado deja de entrar', function () {
    [$agente, $token] = ($this->crearAgente)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/print-agents/{$agente->ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->actingAsPrintAgent($token)->getJson('/api/v1/print-agent/jobs/next')->assertStatus(401);
});

// ---------------------------------------------------------------------------
// El cajón de dinero
// ---------------------------------------------------------------------------

it('abrir el cajón exige PIN, y responde 409 con el permiso que falta', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/printers/{$this->impresoraCaja->ulid}/open-drawer", [
            'reason' => 'Dar cambio a un cliente',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'pos.cash_drawer.open');
});

it('con PIN, encola la apertura y registra al AUTORIZADOR', function () {
    $gerente = gerenteConPin($this->tenant->id);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'pos.cash_drawer.open',
        ])
        ->assertCreated()
        ->json('data.token');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/printers/{$this->impresoraCaja->ulid}/open-drawer", [
            'reason' => 'Dar cambio a un cliente',
            'authorization_token' => $token,
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'drawer_open')
        ->assertJsonPath('data.payload.reason', 'Dar cambio a un cliente')
        // Quien queda registrado es el GERENTE que autorizó, no el dueño que tocó la pantalla (§6.3).
        ->assertJsonPath('data.payload.actor_membership_id', $gerente->id);

    app(TenantContext::class)->set($this->tenant->id);

    // Sin ticket: no imprime nada, manda la secuencia que abre el cajón.
    expect(PrintJob::query()->where('kind', 'drawer_open')->sole()->pos_ticket_id)->toBeNull();
});

it('el cajón no se abre por una impresora que no lo tiene', function () {
    gerenteConPin($this->tenant->id);

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'pos.cash_drawer.open',
        ])
        ->json('data.token');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/printers/{$this->impresoraCocina->ulid}/open-drawer", [
            'reason' => 'Dar cambio',
            'authorization_token' => $token,
        ])
        ->assertStatus(409);
});

/**
 * Un gerente con PIN, para autorizar la apertura del cajón.
 */
function gerenteConPin(int $tenantId): TenantMembership
{
    return app(TenantContext::class)->runFor($tenantId, function (): TenantMembership {
        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->sole();
        $usuario = User::factory()->create();

        $gerente = TenantMembership::factory()->create([
            'user_id' => $usuario->id,
            'employee_code' => 'G001',
            'has_all_branches' => true,
            'default_role_id' => $rol->id,
        ]);

        $usuario->syncRoles([$rol]);

        app(ManageMembershipPin::class)->set($gerente, '1111');

        return $gerente;
    });
}

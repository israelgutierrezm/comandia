<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * API DE EXISTENCIAS Y MOVIMIENTOS MANUALES (paso 2)
 *
 * Lo que se cuida aquí, además del CRUD:
 *
 *   - **El almacén tiene que estar al alcance de quien opera.** El `tenant_id` protege del negocio ajeno, no de
 *     la sucursal ajena dentro del propio. Es el mismo hueco que cierra el override de precio por sucursal.
 *   - **Los tres endpoints son tres permisos.** Quien puede registrar entradas no necesariamente puede ajustar.
 *   - **El ajuste exige dirección y nota.** Es la confesión de un descuadre y sin explicación no se puede
 *     investigar meses después.
 *   - **La valuación es a último costo** (D152): un movimiento sin costo explícito toma el vigente.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Inventario',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Ofelia',
        ownerPaternalSurname: 'Zamora',
        plainPassword: 'contrasena-larga-1',
        branchName: 'Matriz',
        branchCode: 'MTZ',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate saladet',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Cuerpo mínimo de un movimiento sobre el jitomate en la matriz. */
function cuerpo(array $extra = []): array
{
    return array_merge([
        'warehouse_ulid' => test()->warehouse->ulid,
        'article_ulid' => test()->jitomate->ulid,
        'quantity' => '1000',
    ], $extra);
}

/** Asigna un rol plantilla y lo devuelve, para probar por ROL ACTIVO (D9). */
function conRol(string $nombre): Role
{
    app(TenantContext::class)->set(test()->tenant->id);

    $rol = Role::query()->where('name', $nombre)->firstOrFail();
    test()->owner->syncRoles([$rol]);

    app(TenantContext::class)->forget();

    return $rol;
}

// ---------------------------------------------------------------- Entradas

it('registra una entrada manual y deja el saldo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['notes' => 'Muestras del proveedor']))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'manual_entry')
        ->assertJsonPath('data.kind_label', 'Entrada manual')
        ->assertJsonPath('data.direction', 'in')
        ->assertJsonPath('data.quantity', '1000.0000')
        ->assertJsonPath('data.balance_after', '1000.0000')
        ->assertJsonPath('data.article.name', 'Jitomate saladet');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/stock")
        ->assertOk()
        ->assertJsonPath('data.0.quantity', '1000.0000')
        ->assertJsonPath('data.0.is_negative', false);
});

it('la carga inicial se distingue de una entrada normal', function () {
    // No es lo mismo: la carga inicial no es movimiento del periodo, y sumarla al mes daría un número inflado
    // el primer mes de operación.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['is_initial_load' => true]))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'initial_load')
        ->assertJsonPath('data.kind_label', 'Carga inicial');
});

it('valúa al COSTO VIGENTE cuando no se manda uno', function () {
    // D152: valuación a último costo. Aquí se ve la dependencia declarada `Inventory → Costing`.
    app(TenantContext::class)->set($this->tenant->id);
    app(CaptureArticleCost::class)->atUnitCost($this->jitomate, '0.0320');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['quantity' => '12000']))
        ->assertCreated()
        ->assertJsonPath('data.unit_cost', '0.0320')
        // 12 000 × 0.0320, congelado como monto a dos decimales.
        ->assertJsonPath('data.total_cost', '384.00');
});

it('un costo explícito gana al vigente: la carga inicial trae el suyo', function () {
    app(TenantContext::class)->set($this->tenant->id);
    app(CaptureArticleCost::class)->atUnitCost($this->jitomate, '0.0320');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo([
            'quantity' => '1000',
            'unit_cost' => '0.0250',
            'is_initial_load' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.unit_cost', '0.0250');
});

it('un artículo sin costo capturado se mueve SIN costo, no con cero', function () {
    // Cero diría que la mercancía es gratis, y de ahí saldría un valor de inventario falso.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo())
        ->assertCreated()
        ->assertJsonPath('data.unit_cost', null)
        ->assertJsonPath('data.total_cost', null);
});

// ------------------------------------------------------------------ Salidas

it('registra una salida manual y admite dejar el saldo en NEGATIVO', function () {
    // §6.2: el POS nunca se bloquea por inventario. Un negativo es información —«vendiste más de lo que el
    // sistema creía»— y esconderlo perdería la señal que el conteo necesita.
    // La salida devuelve una LISTA: cuando el artículo lleva lotes, FEFO la parte y cada renglón dice de qué
    // partida física salió. La forma es lista **siempre**, aunque haya un solo movimiento, para que el día que
    // un artículo empiece a llevar lotes ninguna integración se rompa sin avisar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-exits', cuerpo(['quantity' => '250', 'notes' => 'Consumo interno']))
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'manual_exit')
        ->assertJsonPath('data.0.direction', 'out')
        ->assertJsonPath('data.0.signed_quantity', '-250.0000')
        ->assertJsonPath('data.0.balance_after', '-250.0000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/stocks?only_negative=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_negative', true);
});

it('no acepta la dirección en una entrada ni en una salida', function () {
    // La dirección es del TIPO. Mandarla sería pedirle al servidor que contradiga su propio endpoint, y se
    // rechaza en lugar de ignorarse: quien la manda cree que va a ocurrir algo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['direction' => 'out']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['direction']]);
});

// ------------------------------------------------------------------ Ajustes

it('el ajuste EXIGE dirección y nota', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-adjustments', cuerpo())
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['direction', 'notes']]);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-adjustments', cuerpo([
            'direction' => 'out',
            'notes' => 'El conteo del viernes dio 8 y el sistema decía 10.',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'manual_adjustment')
        ->assertJsonPath('data.kind_label', 'Ajuste sin explicación')
        ->assertJsonPath('data.direction', 'out');
});

// -------------------------------------------------------------------- Lotes

it('el lote tiene que ser del artículo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();
    $queso = Article::create(['name' => 'Queso', 'base_unit_id' => $gramo->id, 'is_supply' => true]);

    $loteAjeno = ArticleLot::create([
        'article_id' => $queso->id,
        'code' => 'L-2026-A',
        'received_at' => now()->toDateString(),
    ]);

    app(TenantContext::class)->forget();

    // El lote existe, así que la FK está satisfecha: sin esta validación el movimiento pasaría y mezclaría dos
    // existencias distintas bajo el mismo saldo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['lot_ulid' => $loteAjeno->ulid]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['lot_ulid']]);
});

// ------------------------------------------------------------------ Alcance

it('un almacén de otra sucursal está FUERA de alcance', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $otraSucursal = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);
    $almacenAjeno = Warehouse::create([
        'branch_id' => $otraSucursal->id,
        'kind' => WarehouseKind::Branch,
        'code' => 'POLA-ALM',
        'name' => 'Almacén Polanco',
    ]);

    // Un almacenista DISTINTO del propietario, con alcance sólo a la matriz. Tiene que ser otra persona: una
    // membresía es única por (negocio, usuario), y el propietario ya tiene la suya con alcance total.
    $almacenista = User::factory()->create(['first_name' => 'Beto', 'paternal_surname' => 'Nava']);

    $limitada = TenantMembership::factory()->create([
        'user_id' => $almacenista->id,
        'employee_code' => 'A001',
        'has_all_branches' => false,
    ]);
    $limitada->branchScopes()->create(['branch_id' => $this->branch->id]);

    $rol = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $almacenista->syncRoles([$rol]);
    $limitada->update(['default_role_id' => $rol->id]);

    app(TenantContext::class)->forget();

    // Puede registrar en SU sucursal…
    $this->actingAsSpa($almacenista, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo(['quantity' => '10']))
        ->assertCreated();

    // …y no en la ajena. El `tenant_id` no lo protege de esto: la sucursal es del mismo negocio.
    $this->actingAsSpa($almacenista, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', [
            'warehouse_ulid' => $almacenAjeno->ulid,
            'article_ulid' => $this->jitomate->ulid,
            'quantity' => '100',
        ])
        ->assertForbidden();
});

it('un almacén CENTRAL no exige alcance de sucursal', function () {
    // Un almacén central no pertenece a ninguna sucursal: surte a todas (D11). Exigir alcance ahí lo dejaría
    // inoperable para todo el mundo.
    app(TenantContext::class)->set($this->tenant->id);

    $central = Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Central,
        'code' => 'CEN',
        'name' => 'Almacén central',
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', [
            'warehouse_ulid' => $central->ulid,
            'article_ulid' => $this->jitomate->ulid,
            'quantity' => '5000',
        ])
        ->assertCreated();
});

it('el listado de un almacén muestra sólo lo que hay en ÉL', function () {
    // La vista del almacenista. Existe aparte del listado general porque tiene su propio índice
    // —`(tenant, almacén, cantidad)`— y porque es la pregunta que se hace parado frente al estante.
    app(TenantContext::class)->set($this->tenant->id);

    $central = Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Central,
        'code' => 'CEN',
        'name' => 'Almacén central',
    ]);

    // El mismo artículo en dos almacenes, con cantidades distintas.
    foreach ([[$this->warehouse, '400.0000'], [$central, '900.0000']] as [$almacen, $cantidad]) {
        app(RecordStockMovement::class)->record(
            warehouse: $almacen,
            article: $this->jitomate,
            kind: StockMovementKind::ManualEntry,
            quantity: $cantidad,
        );
    }

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/warehouses/{$central->ulid}/stocks")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.quantity', '900.0000')
        ->assertJsonPath('data.0.warehouse.code', 'CEN')
        // Un almacén central no pertenece a ninguna sucursal: surte a todas (D11).
        ->assertJsonPath('data.0.warehouse.branch', null);

    // Y el de la matriz muestra el otro saldo, no la suma.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/warehouses/{$this->warehouse->ulid}/stocks")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.quantity', '400.0000');
});

// ------------------------------------------------------------------- Kardex

it('el kardex se lee del último movimiento hacia atrás, con su saldo', function () {
    foreach (['100', '200', '300'] as $cantidad) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/stock-entries', cuerpo(['quantity' => $cantidad]))
            ->assertCreated();
    }

    $kardex = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/kardex")
        ->assertOk()
        ->json('data');

    expect(array_column($kardex, 'quantity'))->toBe(['300.0000', '200.0000', '100.0000'])
        ->and(array_column($kardex, 'balance_after'))->toBe(['600.0000', '300.0000', '100.0000']);
});

it('el kardex filtra por tipo de movimiento', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-entries', cuerpo())->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-exits', cuerpo(['quantity' => '50']))->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/kardex?kind=manual_exit")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'manual_exit');
});

it('el kardex rechaza un filtro que no está en la whitelist', function () {
    // §8: lo que no está declarado no existe. Un filtro ignorado en silencio devolvería la lista completa a
    // quien cree estar viendo una filtrada — el peor resultado, porque parece correcto.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/kardex?actor=cualquiera")
        ->assertStatus(422);
});

it('el catálogo de tipos de movimiento viene del servidor con sus etiquetas', function () {
    // La lección de D139: una lista de etiquetas escrita a mano en el cliente acaba diciendo algo distinto de
    // lo que dice el servidor.
    $tipos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/stock-movement-kinds')
        ->assertOk()
        ->json('data');

    expect($tipos)->toHaveCount(count(StockMovementKind::cases()));

    $merma = collect($tipos)->firstWhere('value', 'waste');

    expect($merma['label'])->toBe('Merma')
        ->and($merma['direction'])->toBe('out');

    // Los ajustes no tienen dirección fija, y el cliente lo sabe por el `null`.
    expect(collect($tipos)->firstWhere('value', 'manual_adjustment')['direction'])->toBeNull();
});

// -------------------------------------------------------------- Autorización

it('el mesero no ve existencias ni registra movimientos', function () {
    $mesero = conRol(RoleTemplates::WAITER);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/stocks')
        ->assertForbidden();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson('/api/v1/stock-entries', cuerpo())
        ->assertForbidden();
});

it('el ALMACENISTA registra los tres tipos de movimiento', function () {
    // Es su trabajo: recibe mercancía, saca consumo interno y cuadra números. Si esto falla, el rol que existe
    // para operar el inventario no puede operarlo.
    $almacenista = conRol(RoleTemplates::WAREHOUSE_KEEPER);

    foreach ([
        ['stock-entries', []],
        ['stock-exits', []],
        ['stock-adjustments', ['direction' => 'in', 'notes' => 'Cuadre del viernes']],
    ] as [$ruta, $extra]) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->withHeader('X-Role', $almacenista->ulid)
            ->postJson("/api/v1/{$ruta}", cuerpo(array_merge(['quantity' => '10'], $extra)))
            ->assertCreated();
    }

    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): int => StockMovement::query()->count(),
    ))->toBe(3);
});

it('las existencias de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'ajeno@cafe.mx',
        ownerFirstName: 'Hugo',
        ownerPaternalSurname: 'Fierro',
        plainPassword: 'contrasena-larga-2',
        branchCode: 'AJN',
    );

    // El movimiento del primer negocio se registra por el SERVICIO y no por la API: el cliente de pruebas
    // conserva la sesión entre peticiones, así que autenticar a dos usuarios distintos en la misma prueba deja
    // la segunda sin sesión válida. Lo que se prueba aquí es el aislamiento, no el endpoint de escritura —ése
    // ya tiene sus propias pruebas.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(RecordStockMovement::class)->record(
        warehouse: $this->warehouse,
        article: $this->jitomate,
        kind: StockMovementKind::ManualEntry,
        quantity: '1000.0000',
    ));

    // El otro negocio no ve nada. Y su listado no está vacío por casualidad: no tiene existencias propias.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/stocks')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Y el artículo ajeno no existe para él: 404 y no 403, para no confirmar que existe en otro negocio.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/kardex")
        ->assertNotFound();
});

<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Domain\Enums\PosAccountOperationKind;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperation;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperationItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosPayment;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * DIVIDIR, MOVER Y JUNTAR (§6.3, §4.5, paso 12)
 *
 * ## Las tres cosas que estas pruebas existen para demostrar
 *
 * **Todo queda historizado.** Sin `pos_account_operations`, mover un item a otra cuenta que después se cancela es
 * indistinguible de haberlo capturado allí desde el principio. Ése es el hueco por el que se va la mercancía en un bar.
 *
 * **Dividir reparte el IMPORTE, no los items** — y el centavo que sobra tiene que ir a alguien, o el negocio cobra de
 * menos en cada división.
 *
 * **Ninguna operación toca una cuenta con pagos.** Mover mercancía dejaría el dinero donde estaba y el ticket ya
 * impreso diría una cosa mientras la cuenta dice otra.
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
    $categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '50.00',
        'is_available_in_pos' => true,
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();

    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);

    $plan = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Planta baja', 'is_default' => true]);
    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón']);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id, 'floor_zone_id' => $zona->id, 'code' => 'M1', 'seats' => 4,
    ]);

    $this->otraMesa = RestaurantTable::create([
        'branch_id' => $this->branch->id, 'floor_zone_id' => $zona->id, 'code' => 'M2', 'seats' => 2,
    ]);

    app(TenantContext::class)->forget();

    $this->abrirCaja = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->terminal->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated();

    /** Abre una cuenta de barra con N cervezas y devuelve su ULID. */
    $this->cuentaCon = function (string $cantidad, ?string $tableUlid = null): string {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', $tableUlid === null
                ? ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra '.$cantidad]
                : ['table_ulid' => $tableUlid])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => $cantidad]],
            ])
            ->assertCreated();

        return $cuenta;
    };

    /** Divide una cuenta y devuelve sus partes tal como las publica el servidor. */
    $this->dividir = fn (string $cuenta, int $partes): array => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => $partes])
        ->assertOk()
        ->json('data');

    /** Cobra en efectivo el monto indicado. */
    $this->cobrarEfectivo = fn (string $cuenta, string $monto) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo->ulid, 'amount' => $monto]],
        ]);

    /** Cancela una cuenta con un motivo cualquiera. */
    $this->cancelar = fn (string $cuenta) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", ['reason' => 'Se equivocaron al dividir']);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Dividir
// ---------------------------------------------------------------------------

it('divide en partes iguales SIN repartir los items', function () {
    // §6.3 pide dividir «entre cuatro» una botella que nadie pidió individualmente. Repartir items no lo permitiría.
    $cuenta = ($this->cuentaCon)('4', $this->mesa->ulid);

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 4])
        ->assertOk()
        ->assertJsonCount(4, 'data');

    // Cada parte lleva 50 de los 200.
    foreach ($respuesta->json('data') as $parte) {
        expect($parte['totals']['total'])->toBe('50.00');
    }

    app(TenantContext::class)->set($this->tenant->id);

    // Los items siguen en la MADRE.
    $madre = PosAccount::query()->where('ulid', $cuenta)->sole();
    expect(PosOrderItem::query()->where('pos_account_id', $madre->id)->count())->toBe(1);
    expect((string) $madre->total)->toBe('200.00');
});

it('el CENTAVO que sobra se le carga a la primera parte', function () {
    // 100 entre 3 son 33.33 tres veces: 99.99. El centavo que falta no puede evaporarse, o el negocio cobra de menos en
    // cada división. Se le carga a la primera: es arbitrario y es honesto.
    app(TenantContext::class)->set($this->tenant->id);
    $this->cerveza->update(['base_price' => '100.00']);
    app(TenantContext::class)->forget();

    $cuenta = ($this->cuentaCon)('1');

    $partes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 3])
        ->assertOk()
        ->json('data');

    expect($partes[0]['totals']['total'])->toBe('33.34');
    expect($partes[1]['totals']['total'])->toBe('33.33');
    expect($partes[2]['totals']['total'])->toBe('33.33');

    // Y suman exactamente el total.
    $suma = array_reduce($partes, fn (string $c, array $p): string => bcadd($c, $p['totals']['total'], 2), '0.00');
    expect($suma)->toBe('100.00');
});

it('la madre queda pagada cuando TODAS sus partes lo están, y libera la mesa', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2', $this->mesa->ulid);

    $partes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])
        ->assertOk()
        ->json('data');

    $cobrar = fn (string $ulid) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$ulid}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo->ulid, 'amount' => '50.00']],
        ])->assertOk();

    $cobrar($partes[0]['ulid']);

    // Con una parte pagada, la mesa sigue ocupada: falta cobrar la otra.
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);

    $cobrar($partes[1]['ulid']);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertJsonPath('data.status', 'paid');

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('una parte no se vuelve a dividir', function () {
    $cuenta = ($this->cuentaCon)('2');

    $parte = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])
        ->json('data.0.ulid');

    // Dividir una parte otra vez daría un árbol que nadie sabría cobrar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$parte}/split", ['parts' => 2])
        ->assertStatus(409);
});

it('no se divide dos veces la misma cuenta', function () {
    $cuenta = ($this->cuentaCon)('2');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 2])->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/split", ['parts' => 3])->assertStatus(409);
});

// ---------------------------------------------------------------------------
// Dividir: el invariante (D262) — lo de la madre se cobra UNA vez, y sólo por sus partes
// ---------------------------------------------------------------------------

it('la cuenta DIVIDIDA no se cobra directo: se cobra sólo por sus partes', function () {
    // Las partes ya llevan todo su importe. Cobrar la madre completa además sería cobrar dos veces lo mismo — y la madre
    // emitiría su propio ticket y su propia venta.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2', $this->mesa->ulid);
    $partes = ($this->dividir)($cuenta, 2);

    ($this->cobrarEfectivo)($cuenta, '100.00')->assertStatus(409);

    // Y el recurso lo dice, para que la pantalla no ofrezca ni el cobro ni la captura en la madre.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->assertJsonPath('data.is_split', true)
        ->assertJsonPath('data.is_split_part', false)
        ->assertJsonPath('data.split_of', null)
        ->assertJsonPath('data.accepts_items', false)
        ->assertJsonPath('data.accepts_payments', false)
        ->assertJsonCount(2, 'data.split_parts')
        ->assertJsonPath('data.split_parts.0.ulid', $partes[0]['ulid'])
        ->assertJsonPath('data.split_parts.1.total', '50.00');

    // Cada parte sabe de quién es parte, y ella sí se cobra.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$partes[1]['ulid']}")
        ->assertOk()
        ->assertJsonPath('data.is_split', false)
        ->assertJsonPath('data.is_split_part', true)
        ->assertJsonPath('data.split_of.ulid', $cuenta)
        ->assertJsonPath('data.split_of.display_name', 'Mesa M1')
        ->assertJsonPath('data.accepts_items', false)
        ->assertJsonPath('data.accepts_payments', true);

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosPayment::query()->count())->toBe(0);
});

it('la cuenta dividida NO admite captura ni cambios de importe: lo nuevo nunca entraría en las partes', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2');
    $otra = ($this->cuentaCon)('1');
    ($this->dividir)($cuenta, 2);

    // Capturar en la madre subiría su total, pero las partes ya están fijas: lo nuevo no lo pagaría nadie.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '1']],
        ])
        ->assertStatus(409);

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->where('pos_account_id', PosAccount::query()->where('ulid', $cuenta)->sole()->id)->sole();
    app(TenantContext::class)->forget();

    // Quitar un artículo la dejaría por debajo de lo que suman sus partes: el cliente pagaría de más.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", ['item_ulids' => [$item->ulid]])
        ->assertStatus(409);

    // Descontar tampoco: el descuento se asentaría en el diario y las partes se cobrarían completas. Responde el
    // conflicto de la división, no la petición de PIN — no tiene caso pedir una autorización para algo imposible.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/discounts", [
            'kind' => 'percentage',
            'value' => '10',
            'reason' => 'Cliente frecuente',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');

    // Ni sacarle mercancía ni juntarla con otra: se cobraría aquí por sus partes Y allá.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/move-items", [
            'target_account_ulid' => $otra,
            'item_ulids' => [$item->ulid],
        ])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/merge", ['target_account_ulid' => $otra])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.totals.total', '100.00')
        ->assertJsonCount(1, 'data.items');
});

it('una PARTE no admite captura, descuentos ni mercancía ajena: su importe se fijó al dividir', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2');
    $otra = ($this->cuentaCon)('1');
    $parte = ($this->dividir)($cuenta, 2)[0]['ulid'];

    // Una parte no se recalcula (D262): lo que se capturara en ella se quedaría sin cobrar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$parte}/orders", [
            'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '1']],
        ])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$parte}/discounts", [
            'kind' => 'amount',
            'value' => '10',
            'reason' => 'Cliente frecuente',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');

    app(TenantContext::class)->set($this->tenant->id);
    $ajeno = PosOrderItem::query()->where('pos_account_id', PosAccount::query()->where('ulid', $otra)->sole()->id)->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$otra}/move-items", [
            'target_account_ulid' => $parte,
            'item_ulids' => [$ajeno->ulid],
        ])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$otra}/merge", ['target_account_ulid' => $parte])
        ->assertStatus(409);

    // Ni mesa: la mesa la ocupa la madre, y dos cuentas en la misma mesa harían que liberarla dependiera de cuál se
    // cobrara primero (D262).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$parte}/table", ['table_ulid' => $this->otraMesa->ulid])
        ->assertStatus(409);

    expect($this->otraMesa->refresh()->status)->toBe(TableStatus::Free);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$parte}")
        ->assertOk()
        ->assertJsonPath('data.totals.total', '50.00')
        ->assertJsonPath('data.table', null)
        ->assertJsonCount(0, 'data.items');
});

it('la cuenta dividida sigue mandando a preparar lo que tenía capturado', function () {
    // Dividir congela el DINERO, no la cocina: lo capturado antes de dividir tiene que poder salir a preparar.
    $cuenta = ($this->cuentaCon)('2');
    ($this->dividir)($cuenta, 2);

    $orden = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.orders.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command")
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosOrderItem::query()->sole()->status)->toBe(PosOrderItemStatus::Commanded);
});

it('la cuenta dividida no se cancela mientras tenga partes vivas', function () {
    // Cancelarla dejaría las partes cobrables de una cuenta que ya no existe, y al cobrarlas la madre «revivía» pagada.
    $cuenta = ($this->cuentaCon)('2', $this->mesa->ulid);
    ($this->dividir)($cuenta, 2);

    ($this->cancelar)($cuenta)->assertStatus(409);

    // `allowed_next` sigue siendo la máquina de estados (como con los pagos, que la pantalla muestra deshabilitado con su
    // motivo); lo que dice POR QUÉ no se cancela es `is_split`.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.is_split', true);

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);
});

it('una parte NO se cancela si su división ya tiene pagos', function () {
    // Su importe se quedaría sin cobrar y la madre no se saldaría nunca: la mesa quedaría ocupada para siempre.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2', $this->mesa->ulid);
    $partes = ($this->dividir)($cuenta, 2);

    ($this->cobrarEfectivo)($partes[0]['ulid'], '50.00')->assertOk();

    ($this->cancelar)($partes[1]['ulid'])->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$partes[1]['ulid']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.accepts_payments', true);

    // Y la parte que falta se cobra: la madre queda pagada y la mesa se libera. Es la única salida, y funciona.
    ($this->cobrarEfectivo)($partes[1]['ulid'], '50.00')->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertJsonPath('data.status', 'paid');

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('con una parte cancelada, las demás ya no se cobran: la división dejó de sumar el total', function () {
    // «Dividir en cuatro, cancelar una, cobrar tres» es el hueco del bar con otro disfraz: una cuarta parte de la cuenta
    // desaparece sin que nadie la cobre.
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('2', $this->mesa->ulid);
    $partes = ($this->dividir)($cuenta, 2);

    ($this->cancelar)($partes[0]['ulid'])->assertOk();

    ($this->cobrarEfectivo)($partes[1]['ulid'], '50.00')->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$partes[1]['ulid']}")
        ->assertOk()
        ->assertJsonPath('data.accepts_payments', false);

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosPayment::query()->count())->toBe(0);
});

it('cancelar TODAS las partes deshace la división: la cuenta se vuelve a dividir y se cobra', function () {
    ($this->abrirCaja)();
    $cuenta = ($this->cuentaCon)('3', $this->mesa->ulid);
    $partes = ($this->dividir)($cuenta, 2);

    foreach ($partes as $parte) {
        ($this->cancelar)($parte['ulid'])->assertOk();
    }

    // Sin partes vivas deja de estar dividida: vuelve a admitir captura, y se puede repartir de otra forma.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->assertJsonPath('data.is_split', false)
        ->assertJsonPath('data.accepts_items', true);

    $nuevas = ($this->dividir)($cuenta, 3);

    foreach ($nuevas as $parte) {
        ($this->cobrarEfectivo)($parte['ulid'], '50.00')->assertOk();
    }

    // Las partes canceladas de la primera división no cuentan como pendientes: la madre queda pagada y libera la mesa.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertJsonPath('data.status', 'paid');

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

// ---------------------------------------------------------------------------
// Mover items — el hueco del bar
// ---------------------------------------------------------------------------

it('mueve items y deja RASTRO de dónde venían', function () {
    // Es la razón de ser del paso: sin el rastro, mover tres cervezas a una cuenta que después se cancela es
    // indistinguible de haberlas capturado allí desde el principio.
    $origen = ($this->cuentaCon)('3', $this->mesa->ulid);
    $destino = ($this->cuentaCon)('1', $this->otraMesa->ulid);

    app(TenantContext::class)->set($this->tenant->id);
    $cuentaOrigen = PosAccount::query()->where('ulid', $origen)->sole();
    $item = PosOrderItem::query()->where('pos_account_id', $cuentaOrigen->id)->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/move-items", [
            'target_account_ulid' => $destino,
            'item_ulids' => [$item->ulid],
        ])
        ->assertOk()
        // El destino se lleva los 150 además de sus 50.
        ->assertJsonPath('data.totals.total', '200.00');

    app(TenantContext::class)->set($this->tenant->id);

    $operacion = PosAccountOperation::query()->sole();
    expect($operacion->kind)->toBe(PosAccountOperationKind::MoveItems);
    expect($operacion->detail_count)->toBe(1);

    $detalle = PosAccountOperationItem::query()->sole();
    expect((int) $detalle->from_account_id)->toBe($cuentaOrigen->id);
    expect((int) $detalle->pos_order_item_id)->toBe($item->id);

    // El origen se quedó vacío, y su mesa se libera: nadie va a cobrar nada ahí.
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('la ORDEN se queda donde estaba al mover un item YA COMANDADO', function () {
    // La orden describe lo que se preparó: la comanda ya salió por la impresora de la cocina y ese hecho no se mueve.
    //
    // Por eso el item se COMANDA primero. La primera versión de esta prueba movía uno sólo capturado, y lo que afirmaba
    // era justo el defecto: una línea sin comandar que conservaba la orden del origen y que el destino no podía mandar a
    // preparar (ver «lo NO comandado que se pasa…»).
    $origen = ($this->cuentaCon)('2');
    $destino = ($this->cuentaCon)('1');

    $orden = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$origen}")
        ->json('data.orders.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/orders/{$orden}/command")
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $cuentaOrigen = PosAccount::query()->where('ulid', $origen)->sole();
    $item = PosOrderItem::query()->where('pos_account_id', $cuentaOrigen->id)->sole();
    $ordenOriginal = $item->pos_order_id;
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/move-items", [
            'target_account_ulid' => $destino,
            'item_ulids' => [$item->ulid],
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect((int) $item->refresh()->pos_order_id)->toBe((int) $ordenOriginal);
});

it('no se mueven items que no son de la cuenta de origen', function () {
    $origen = ($this->cuentaCon)('1');
    $tercera = ($this->cuentaCon)('1');
    $destino = ($this->cuentaCon)('1');

    app(TenantContext::class)->set($this->tenant->id);
    $ajena = PosAccount::query()->where('ulid', $tercera)->sole();
    $itemAjeno = PosOrderItem::query()->where('pos_account_id', $ajena->id)->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/move-items", [
            'target_account_ulid' => $destino,
            'item_ulids' => [$itemAjeno->ulid],
        ])
        ->assertStatus(409);
});

it('pasar SÓLO ALGUNOS artículos NO libera la mesa de origen', function () {
    // A la cuenta de origen le queda algo que cobrar: su mesa sigue en servicio. Liberarla dejaría sentar a otro grupo
    // encima de una cuenta viva.
    $origen = ($this->cuentaCon)('3', $this->mesa->ulid);

    // Una segunda línea (con nota, para que no se sume a la primera).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/orders", [
            'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '1', 'note' => 'Sin vaso']],
        ])
        ->assertCreated();

    $destino = ($this->cuentaCon)('1', $this->otraMesa->ulid);

    app(TenantContext::class)->set($this->tenant->id);
    $cuentaOrigen = PosAccount::query()->where('ulid', $origen)->sole();
    $tres = PosOrderItem::query()->where('pos_account_id', $cuentaOrigen->id)->whereNull('note')->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/move-items", [
            'target_account_ulid' => $destino,
            'item_ulids' => [$tres->ulid],
        ])
        ->assertOk();

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$origen}")
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.totals.total', '50.00');
});

it('lo NO comandado que se pasa a otra cuenta se manda a preparar desde la cuenta DESTINO', function () {
    // Una línea capturada y sin comandar no tiene comanda que la ate a su orden: nadie la preparó. Se va a la orden
    // borrador del destino, que es desde donde la pantalla la va a comandar (D307: cada línea publica su orden).
    $origen = ($this->cuentaCon)('2');
    $destino = ($this->cuentaCon)('1');

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->where('pos_account_id', PosAccount::query()->where('ulid', $origen)->sole()->id)->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/move-items", [
            'target_account_ulid' => $destino,
            'item_ulids' => [$item->ulid],
        ])
        ->assertOk();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$destino}")
        ->assertOk()
        ->json('data');

    $movido = collect($datos['items'])->firstWhere('ulid', $item->ulid);

    // Su orden es una orden de la cuenta DESTINO…
    expect(array_column($datos['orders'], 'ulid'))->toContain($movido['order_ulid']);

    // …y comandarla desde ahí la manda a preparar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$destino}/orders/{$movido['order_ulid']}/command")
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    expect($item->refresh()->status)->toBe(PosOrderItemStatus::Commanded);
});

it('al JUNTAR, lo no comandado queda comandable desde la cuenta destino', function () {
    // La cuenta de origen queda cancelada: si sus líneas pendientes conservaran su orden, nadie podría mandarlas a
    // preparar nunca.
    $origen = ($this->cuentaCon)('2');
    $destino = ($this->cuentaCon)('1');

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->where('pos_account_id', PosAccount::query()->where('ulid', $origen)->sole()->id)->sole();
    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/merge", ['target_account_ulid' => $destino])
        ->assertOk()
        ->json('data');

    $movido = collect($datos['items'])->firstWhere('ulid', $item->ulid);

    expect(array_column($datos['orders'], 'ulid'))->toContain($movido['order_ulid']);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$destino}/orders/{$movido['order_ulid']}/command")
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    expect($item->refresh()->status)->toBe(PosOrderItemStatus::Commanded);
});

// ---------------------------------------------------------------------------
// Juntar
// ---------------------------------------------------------------------------

it('junta una cuenta en otra y CANCELA la de origen con su motivo', function () {
    $origen = ($this->cuentaCon)('2', $this->mesa->ulid);
    $destino = ($this->cuentaCon)('1', $this->otraMesa->ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/merge", ['target_account_ulid' => $destino])
        ->assertOk()
        ->assertJsonPath('data.totals.total', '150.00');

    app(TenantContext::class)->set($this->tenant->id);

    $cancelada = PosAccount::query()->where('ulid', $origen)->sole();

    // «Cancelada» y no «pagada» —no entró dinero— ni borrada —ocurrió, y su historial la cita—. El motivo dice a dónde
    // se fue.
    expect($cancelada->status->value)->toBe('cancelled');
    expect($cancelada->cancelled_reason)->toContain('Juntada en la cuenta');

    expect(PosAccountOperation::query()->sole()->kind)->toBe(PosAccountOperationKind::Merge);

    // Y la mesa del origen se libera.
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('una cuenta no se junta consigo misma', function () {
    $cuenta = ($this->cuentaCon)('1');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/merge", ['target_account_ulid' => $cuenta])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// La regla que protege el dinero
// ---------------------------------------------------------------------------

it('NINGUNA operación toca una cuenta con pagos aplicados', function () {
    // Mover mercancía dejaría el dinero donde estaba y el ticket ya impreso diría una cosa mientras la cuenta dice
    // otra. Y las propinas cobradas siguen siendo de quien las ganó (D233): esta regla es lo que lo garantiza.
    ($this->abrirCaja)();

    $conPago = ($this->cuentaCon)('2');
    $otra = ($this->cuentaCon)('1');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$conPago}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo->ulid, 'amount' => '20.00']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$conPago}/split", ['parts' => 2])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$conPago}/merge", ['target_account_ulid' => $otra])
        ->assertStatus(409);

    app(TenantContext::class)->set($this->tenant->id);
    $cuenta = PosAccount::query()->where('ulid', $conPago)->sole();
    $item = PosOrderItem::query()->where('pos_account_id', $cuenta->id)->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$conPago}/move-items", [
            'target_account_ulid' => $otra,
            'item_ulids' => [$item->ulid],
        ])
        ->assertStatus(409);
});

it('una operación es INMUTABLE', function () {
    $origen = ($this->cuentaCon)('1');
    $destino = ($this->cuentaCon)('1');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/merge", ['target_account_ulid' => $destino])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => PosAccountOperation::query()->sole()->update(['detail_count' => 99]))
        ->toThrow(RuntimeException::class);
});

it('las operaciones de un negocio son invisibles para otro', function () {
    $origen = ($this->cuentaCon)('1');
    $destino = ($this->cuentaCon)('1');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$origen}/merge", ['target_account_ulid' => $destino])
        ->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    app(TenantContext::class)->set($otro['tenant']->id);

    expect(PosAccountOperation::query()->count())->toBe(0);
});

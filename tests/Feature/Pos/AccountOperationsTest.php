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
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperation;
use App\Modules\Pos\Infrastructure\Models\PosAccountOperationItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
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

it('la ORDEN se queda donde estaba al mover un item', function () {
    // La orden describe lo que se preparó: la comanda ya salió por la impresora de la cocina y ese hecho no se mueve.
    $origen = ($this->cuentaCon)('2');
    $destino = ($this->cuentaCon)('1');

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

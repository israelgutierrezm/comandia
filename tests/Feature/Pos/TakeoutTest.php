<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Application\TakeoutNumberAllocator;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\DB;

/**
 * PARA LLEVAR: EL NÚMERO QUE SE GRITA Y LOS ESTADOS DE ENTREGA (§6.3, paso 14)
 *
 * ## Por qué el folio de la cuenta no sirve para el mostrador
 *
 * Es un número que crece para siempre: a los tres meses va por A-14238. Nadie grita eso, y quien lo oye no lo retiene.
 * El número de mostrador vuelve a 1 cada jornada y se queda en dos cifras.
 *
 * ## Y por qué una tabla de contadores y no `MAX(...) + 1`
 *
 * Dos pedidos simultáneos leerían el mismo máximo y gritarían el mismo número: dos personas levantándose por la misma
 * bolsa.
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

    $this->torta = Article::create([
        'name' => 'Torta de jamón',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '60.00',
        'is_available_in_pos' => true,
    ]);

    $plan = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Planta baja', 'is_default' => true]);
    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón']);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id, 'floor_zone_id' => $zona->id, 'code' => 'M1', 'seats' => 4,
    ]);

    app(TenantContext::class)->forget();

    /** Abre un pedido para llevar y devuelve la respuesta. */
    $this->paraLlevar = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'takeout' => true,
        ]);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// El número
// ---------------------------------------------------------------------------

it('numera desde 1 y se identifica por el número, no por el folio', function () {
    ($this->paraLlevar)()
        ->assertCreated()
        ->assertJsonPath('data.kind', 'takeout')
        ->assertJsonPath('data.takeout_number', 1)
        // Lo que se grita en el mostrador. El folio existe y no sirve para eso.
        ->assertJsonPath('data.display_name', 'Para llevar #1')
        ->assertJsonPath('data.delivery_status', 'pending');

    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 2);
    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 3);
});

it('cada SUCURSAL numera por su cuenta', function () {
    // Dos mostradores distintos, dos filas de gente distintas: compartir el contador haría que el número 4 de Roma
    // saltara al 7 porque Polanco vendió tres.
    app(TenantContext::class)->set($this->tenant->id);

    $otra = Branch::create(['name' => 'Polanco', 'code' => 'POLA']);
    Warehouse::create(['branch_id' => $otra->id, 'kind' => 'branch', 'code' => 'ALM-POLA', 'name' => 'Almacén']);

    app(TenantContext::class)->forget();

    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 1);
    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 2);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $otra->ulid, 'takeout' => true])
        ->assertCreated()
        ->assertJsonPath('data.takeout_number', 1);
});

it('el contador REINICIA con la jornada', function () {
    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 1);
    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 2);

    // Al día siguiente vuelve a empezar. Es el requisito entero, y es la razón por la que no se puede reutilizar
    // `DocumentNumberAllocator`: aquél no reinicia nunca.
    $this->travel(1)->days();

    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 1);
});

it('un pedido que no llega a crearse NO consume número', function () {
    // El número se asigna dentro de la transacción de la cuenta: si la cuenta no se crea, el número tampoco se gasta.
    // Al revés dejaría huecos, y un hueco en el mostrador es un número que se grita y nadie recoge.
    app(TenantContext::class)->set($this->tenant->id);

    try {
        DB::transaction(function (): void {
            app(TakeoutNumberAllocator::class)->next($this->branch);

            throw new RuntimeException('El pedido se canceló a medio capturar.');
        });
    } catch (RuntimeException) {
        // Esperado.
    }

    app(TenantContext::class)->forget();

    ($this->paraLlevar)()->assertCreated()->assertJsonPath('data.takeout_number', 1);
});

// ---------------------------------------------------------------------------
// La forma del pedido
// ---------------------------------------------------------------------------

it('un pedido para llevar NO ocupa mesa', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'takeout' => true,
            'table_ulid' => $this->mesa->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['takeout']]);
});

it('una cuenta de MESA no tiene estado de entrega', function () {
    // En una mesa no hay nada que entregar: se sirve y ya.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
        ->assertCreated()
        ->assertJsonPath('data.delivery_status', null)
        ->assertJsonPath('data.takeout_number', null);
});

// ---------------------------------------------------------------------------
// Los estados de entrega
// ---------------------------------------------------------------------------

it('avanza de pendiente a listo y a entregado', function () {
    $cuenta = ($this->paraLlevar)()->assertCreated()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'ready'])
        ->assertOk()
        ->assertJsonPath('data.delivery_status', 'ready')
        ->assertJsonPath('data.delivery_allowed_next', ['delivered']);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'delivered'])
        ->assertOk()
        ->assertJsonPath('data.delivery_status', 'delivered')
        // De entregado no se sale: la bolsa ya salió por el mostrador.
        ->assertJsonPath('data.delivery_allowed_next', []);
});

it('se puede saltar de pendiente a ENTREGADO', function () {
    // El cliente estaba esperando de pie y se lo dieron en cuanto salió. Obligar a pasar por «listo» sería un toque de
    // más en el momento de más prisa.
    $cuenta = ($this->paraLlevar)()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'delivered'])
        ->assertOk()
        ->assertJsonPath('data.delivery_status', 'delivered');
});

it('de ENTREGADO no se retrocede', function () {
    $cuenta = ($this->paraLlevar)()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'delivered'])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'ready'])
        ->assertStatus(409);
});

it('una cuenta de mesa no admite estados de entrega', function () {
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'ready'])
        ->assertStatus(409);
});

it('entregar y cobrar son hechos INDEPENDIENTES', function () {
    // `pos.takeout_payment_timing` decide si se cobra al ordenar o al recoger, así que atar el estado de entrega al
    // cobro haría que un negocio que cobra al recoger no pudiera marcar nada como listo hasta tener el dinero.
    $cuenta = ($this->paraLlevar)()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->torta->ulid, 'quantity' => '1']],
        ])
        ->assertCreated();

    // Se marca listo SIN haber cobrado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/delivery", ['delivery_status' => 'ready'])
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.totals.due', '60.00');
});

it('los pedidos de un negocio son invisibles para otro', function () {
    ($this->paraLlevar)()->assertCreated();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    // El contador del otro negocio empieza en 1: los contadores llevan `tenant_id`.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $otro['branch']->ulid, 'takeout' => true])
        ->assertCreated()
        ->assertJsonPath('data.takeout_number', 1);
});

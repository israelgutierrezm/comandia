<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CUENTAS, ÓRDENES E ITEMS — Y LOS CONGELADOS (D28, §6.3)
 *
 * ## La decisión que define este paso
 *
 * El precio, el nombre del artículo, la tasa de IVA y los modificadores con su precio se copian a la línea **al
 * capturarla** y no se releen nunca. Si alguien sube el precio del café a media tarde, las cuentas abiertas siguen
 * cobrando el precio con el que se pidió.
 *
 * Estas pruebas cambian el catálogo DESPUÉS de capturar y verifican que la cuenta no se mueve. Es lo único que demuestra
 * que el congelado existe: sin ese cambio de por medio, una prueba de precios pasa igual leyendo del catálogo.
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

    $unidad = Unit::query()->where('code', 'pza')->sole();

    // Un artículo vendible necesita CATEGORÍA: es un invariante de la Iteración 2 —el punto de venta agrupa la pantalla
    // por categoría y sin ella no tendría dónde aparecer— y lo había olvidado al montar esta prueba. El invariante vive
    // en el modelo justamente para que no se pueda olvidar.
    $categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '45.00',
        'is_available_in_pos' => true,
    ]);

    $this->pan = Article::create([
        'name' => 'Pan dulce',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '20.00',
        'is_available_in_pos' => true,
    ]);

    // Un grupo de modificadores CON cantidad —los 3 shots de D7— y otro sin ella.
    $this->grupoConCantidad = ModifierGroup::create([
        'name' => 'Extras',
        'is_required' => false,
        'allows_quantity' => true,
    ]);

    $this->shot = Modifier::create([
        'modifier_group_id' => $this->grupoConCantidad->id,
        'name' => 'Shot extra',
        'extra_price' => '15.00',
    ]);

    $this->grupoSinCantidad = ModifierGroup::create([
        'name' => 'Temperatura',
        'is_required' => false,
        'allows_quantity' => false,
    ]);

    $this->frio = Modifier::create([
        'modifier_group_id' => $this->grupoSinCantidad->id,
        'name' => 'Frío',
        'extra_price' => '0.00',
    ]);

    $plan = FloorPlan::create([
        'branch_id' => $this->branch->id,
        'name' => 'Planta baja',
        'is_default' => true,
    ]);

    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón']);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $zona->id,
        'code' => 'M1',
        'seats' => 4,
    ]);

    app(TenantContext::class)->forget();

    /** Abre una cuenta en la mesa y devuelve su ULID. */
    $this->abrirEnMesa = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
        ->assertCreated()
        ->json('data.ulid');

    /** Captura una orden de una línea. */
    $this->capturar = function (string $accountUlid, Article $article, string $cantidad = '1', array $extra = []) {
        return $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$accountUlid}/orders", [
                'lines' => [array_merge(['article_ulid' => $article->ulid, 'quantity' => $cantidad], $extra)],
            ]);
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Abrir
// ---------------------------------------------------------------------------

it('abre una cuenta en una mesa y la ocupa', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.display_name', 'Mesa M1')
        ->assertJsonPath('data.table.code', 'M1')
        // El titular es quien abre cuando no se dice otra cosa (D233).
        ->assertJsonPath('data.waiter.employee_code', 'P001');

    expect($respuesta->json('data.folio'))->toBe('A-1');

    // El estado de una mesa lo mueve lo que pasa con sus cuentas (§6.3).
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);
});

it('abre una cuenta de barra con nombre libre', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Señor de lentes',
        ])
        ->assertCreated()
        // Sin mesa, el nombre libre es lo que la identifica en el piso: por eso es obligatorio cuando no hay mesa.
        ->assertJsonPath('data.display_name', 'Señor de lentes')
        ->assertJsonPath('data.table', null);
});

it('una cuenta no lleva mesa Y nombre libre a la vez', function () {
    // No significa nada: una cuenta no está en la mesa 4 y además se llama «Señor de lentes».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'table_ulid' => $this->mesa->ulid,
            'label' => 'Señor de lentes',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['label']]);
});

it('no se abre una segunda cuenta en una mesa ocupada', function () {
    ($this->abrirEnMesa)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $this->mesa->ulid])
        ->assertStatus(409);
});

it('el titular puede ser otra persona que quien abre', function () {
    // El cajero abre la cuenta de barra cuyo titular es la mesera que la atiende, y la propina es de ella (D233). Con
    // una sola columna, la propina acabaría siempre a nombre de quien tocó la pantalla primero.
    app(TenantContext::class)->set($this->tenant->id);

    // Con usuario: el invariante I1 (D66) exige que una membresía sin credenciales tenga perfil de empleado, porque de
    // ahí sale su nombre. Sin una de las dos cosas no tendría nombre, y una cuenta cuyo titular no se puede nombrar no
    // sirve para atribuir una propina.
    $otra = TenantMembership::factory()->create([
        'user_id' => User::factory()->create()->id,
        'employee_code' => 'W002',
        'has_all_branches' => true,
    ]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Barra 1',
            'waiter_ulid' => $otra->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.waiter.employee_code', 'W002')
        ->assertJsonPath('data.opened_by.employee_code', 'P001');
});

// ---------------------------------------------------------------------------
// LOS CONGELADOS
// ---------------------------------------------------------------------------

it('el precio se congela al capturar y no cambia si el catálogo cambia', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe, '2')->assertCreated();

    // Alguien sube el precio del café a media tarde.
    app(TenantContext::class)->set($this->tenant->id);
    $this->cafe->update(['base_price' => '80.00']);
    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    // La cuenta abierta sigue cobrando lo que el cliente aceptó al pedir.
    expect($datos['items'][0]['unit_price'])->toBe('45.00')
        ->and($datos['items'][0]['line_total'])->toBe('90.00')
        ->and($datos['totals']['total'])->toBe('90.00');
});

it('el NOMBRE del artículo también se congela', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    // El artículo se renombra —o se da de baja— después de la venta.
    app(TenantContext::class)->set($this->tenant->id);
    $this->cafe->update(['name' => 'Café de la casa']);
    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    // Un ticket reimpreso un mes después tiene que decir lo que decía el original.
    expect($datos['items'][0]['article_name'])->toBe('Café americano')
        // Y el artículo actual sigue accesible para lo que sí necesita el dato de hoy: el consumo de inventario.
        ->and($datos['items'][0]['article']['name'])->toBe('Café de la casa');
});

it('la tasa de IVA se congela en la línea', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    // El negocio cambia su tasa. Es el paso 2 de D150: los documentos ya emitidos NO se recalculan.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(Settings::class)->setForTenant('tax.vat_rate', 8.0));

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    expect($datos['items'][0]['vat_rate'])->toBe('16.00');
});

it('el IVA se EXTRAE del precio, no se suma', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    // Los precios son IVA incluido (D30): 45.00 al 16 % contiene 6.21 de impuesto (45 − 45/1.16). Sumar la tasa daría
    // 7.20 y un total de 52.20 que el cliente nunca aceptó — es el error más fácil de cometer en un POS.
    expect($datos['items'][0]['vat_amount'])->toBe('6.21')
        ->and($datos['totals']['vat_total'])->toBe('6.21')
        // Y el total NO crece con el IVA.
        ->and($datos['totals']['total'])->toBe('45.00');
});

it('los modificadores se congelan con su nombre y su precio', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe, '1', [
        'modifier_ulids' => [$this->shot->ulid],
        'modifier_quantities' => [$this->shot->ulid => 3],
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $this->shot->update(['name' => 'Shot doble', 'extra_price' => '25.00']);
    app(TenantContext::class)->forget();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    $modificador = $datos['items'][0]['modifiers'][0];

    // Los 3 shots de D7, a su precio de entonces: 15 × 3 = 45, y el renglón cuesta 45 + 45.
    expect($modificador['name'])->toBe('Shot extra')
        ->and($modificador['extra_price'])->toBe('15.00')
        ->and($modificador['quantity'])->toBe(3)
        ->and($modificador['total'])->toBe('45.00')
        ->and($datos['items'][0]['modifiers_total'])->toBe('45.00')
        ->and($datos['items'][0]['line_total'])->toBe('90.00');
});

it('un modificador de un grupo sin cantidad no admite cantidad', function () {
    $cuenta = ($this->abrirEnMesa)();

    // «3 términos medios» no significa nada y produciría un cargo triple por algo que se sirve una vez (D7).
    ($this->capturar)($cuenta, $this->cafe, '1', [
        'modifier_ulids' => [$this->frio->ulid],
        'modifier_quantities' => [$this->frio->ulid => 3],
    ])->assertStatus(422);
});

it('el precio NO se acepta del cliente', function () {
    $cuenta = ($this->abrirEnMesa)();

    // §6.9: el frontend previsualiza, el backend decide. Aceptar un precio del cliente sería la puerta más ancha del
    // sistema — cualquiera se cobraría un café a un peso desde la consola del navegador.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [[
                'article_ulid' => $this->cafe->ulid,
                'quantity' => '1',
                'unit_price' => '1.00',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['lines.0.unit_price']]);
});

it('el precio sale de la SUCURSAL de la cuenta', function () {
    // §6.1 permite override por sucursal: el mismo café cuesta distinto en Roma que en Polanco. Leer el precio maestro
    // sería cobrar mal en la mitad de las sucursales.
    app(TenantContext::class)->set($this->tenant->id);

    $this->cafe->branchOverrides()->create([
        'branch_id' => $this->branch->id,
        'price' => '60.00',
        'is_available_in_pos' => true,
    ]);

    app(TenantContext::class)->forget();

    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    expect($datos['items'][0]['unit_price'])->toBe('60.00');
});

// ---------------------------------------------------------------------------
// Órdenes
// ---------------------------------------------------------------------------

it('cada captura es una orden nueva, numerada dentro de la cuenta', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe)->assertCreated();
    ($this->capturar)($cuenta, $this->pan)->assertCreated();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    // Tres rondas son tres órdenes en la misma cuenta, y eso es lo que permite que la cocina reciba tres comandas en
    // lugar de una que crece (D28).
    expect(array_column($datos['orders'], 'sequence'))->toBe([1, 2])
        ->and($datos['items'])->toHaveCount(2)
        ->and($datos['totals']['total'])->toBe('65.00');
});

it('el total es la suma de las líneas, recalculada', function () {
    $cuenta = ($this->abrirEnMesa)();

    ($this->capturar)($cuenta, $this->cafe, '2')->assertCreated();
    ($this->capturar)($cuenta, $this->pan, '3')->assertCreated();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    // 2 × 45 + 3 × 20 = 150. Y `line_total` lo calcula la BASE como columna generada: una multiplicación de dinero
    // hecha en dos sitios es como se desincronizan los totales (D134).
    expect($datos['totals']['total'])->toBe('150.00')
        ->and($datos['totals']['due'])->toBe('150.00');
});

it('no se capturan artículos que no son vendibles', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $insumo = Article::create([
        'name' => 'Granos de café',
        'base_unit_id' => Unit::query()->where('code', 'g')->sole()->id,
        'is_sellable' => false,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();

    $cuenta = ($this->abrirEnMesa)();

    // Se rechaza en la VALIDACIÓN y no en el servicio: dejarlo pasar acabaría en un 422 después de que el mesero ya lo
    // capturó en la pantalla.
    ($this->capturar)($cuenta, $insumo)->assertStatus(422);
});

// ---------------------------------------------------------------------------
// La máquina de estados
// ---------------------------------------------------------------------------

it('pedir la cuenta es reversible', function () {
    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/bill-request")
        ->assertOk()
        ->assertJsonPath('data.status', 'bill_requested')
        // Sigue admitiendo items porque el negocio no configuró el bloqueo: en un bar, alguien pide la cuenta y a los
        // cinco minutos pide otra cerveza.
        ->assertJsonPath('data.accepts_items', true);

    ($this->capturar)($cuenta, $this->pan)->assertCreated();
});

it('con el bloqueo configurado, pedir la cuenta cierra la captura', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForTenant('pos.lock_items_on_bill_request', true),
    );

    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/bill-request")
        ->assertOk();

    // Los dos comportamientos son legítimos, y por eso es configurable: en un restaurante, pedir la cuenta significa
    // «ya terminamos».
    ($this->capturar)($cuenta, $this->pan)->assertStatus(409);
});

it('una cuenta cerrada se puede reabrir con su permiso', function () {
    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.accepts_items', false);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', 'open');
});

it('pedir la cuenta mueve la MESA a «cuenta solicitada»', function () {
    // §6.4 pinta ese estado en la vista de piso, y hasta el paso 7 nada lo escribía: el enum lo tenía, la pantalla lo
    // sabía dibujar, y ninguna transición llegaba a él. Es la señal de que a esa mesa le falta cobrar y no volver a
    // atenderla — sin ella, el encargado de piso no distingue una mesa que come de una que espera el cobro.
    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/bill-request")
        ->assertOk()
        ->assertJsonPath('data.status', 'bill_requested');

    expect($this->mesa->refresh()->status)->toBe(TableStatus::BillRequested);
});

it('la mesa vuelve a «por limpiar» si el negocio usa ese estado', function () {
    // La regla vive en `Floor` y no en `Pos`, que es el punto del refactor del paso 7: `Pos` sabe que ya no queda nada
    // por cobrar; qué significa liberar lo decide el salón, leyendo su propio ajuste.
    app(TenantContext::class)->set($this->tenant->id);
    app(Settings::class)->setForTenant('floor.use_cleaning_state', true);
    app(TenantContext::class)->forget();

    $cuenta = ($this->abrirEnMesa)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", ['reason' => 'El cliente se fue'])
        ->assertOk();

    expect($this->mesa->refresh()->status)->toBe(TableStatus::NeedsCleaning);
});

it('cancelar una cuenta exige motivo y libera la mesa', function () {
    $cuenta = ($this->abrirEnMesa)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", ['reason' => 'El cliente se fue'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // §6.3: la mesa se libera cuando no queda ninguna cuenta viva en ella. Con `floor.use_cleaning_state` apagado
    // vuelve directo a libre, que es lo que una fonda quiere.
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

// ---------------------------------------------------------------------------
// El candado optimista
// ---------------------------------------------------------------------------

it('la versión evita escribir sobre una cuenta que cambió', function () {
    $cuenta = ($this->abrirEnMesa)();

    $version = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data.version');

    // Otra terminal captura algo: la versión avanza.
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    // Y quien tenía la pantalla abierta manda la versión vieja. §11 lo pide por nombre: «versión de cuenta verificada al
    // pagar». Sin esto, dos terminales pueden cobrar la misma cuenta.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'version' => $version,
            'lines' => [['article_ulid' => $this->pan->ulid, 'quantity' => '1']],
        ])
        ->assertStatus(409);
});

it('sin mandar versión, la operación procede', function () {
    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe)->assertCreated();

    // Es opcional a propósito: un cliente que no la manda acepta el riesgo, y exigirla rompería cualquier integración
    // que todavía no la conozca. La pantalla del POS sí la manda siempre.
    ($this->capturar)($cuenta, $this->pan)->assertCreated();
});

// ---------------------------------------------------------------------------
// Garantías estructurales
// ---------------------------------------------------------------------------

it('la base calcula el total de la línea y rechaza formas imposibles', function () {
    $cuenta = ($this->abrirEnMesa)();
    ($this->capturar)($cuenta, $this->cafe, '2', [
        'modifier_ulids' => [$this->shot->ulid],
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    // 2 × (45 + 15) = 120, calculado por la BASE (D218: se prueba la garantía, no la validación).
    expect((string) PosOrderItem::query()->sole()->line_total)->toBe('120.00');

    // Y una cuenta con mesa Y número de mostrador no se puede ni pintar en una pantalla: el CHECK lo impide.
    expect(fn () => PosAccount::query()->whereKey(
        PosAccount::query()->sole()->id
    )->update(['takeout_number' => 34]))->toThrow(Illuminate\Database\QueryException::class);
});

it('las cuentas de un negocio no se ven desde otro', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => PosAccount::create([
        'branch_id' => $this->branch->id,
        'series' => 'A',
        'folio' => 1,
        'kind' => 'dine_in',
        'label' => 'Barra 1',
        'waiter_membership_id' => $this->membership->id,
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
        ->getJson('/api/v1/pos-accounts')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

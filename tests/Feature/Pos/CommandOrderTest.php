<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use App\Modules\Shared\Domain\Events\PosOrderCommanded;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Event;

/**
 * COMANDAR Y CANCELAR (§6.3, paso 8)
 *
 * ## Las dos cosas que estas pruebas existen para demostrar
 *
 * **Una comanda por área.** Una orden con un café y unos tacos produce DOS papeles: uno a la barra y uno a la cocina.
 * Una comanda con las dos cosas obligaría a la cocina a leer la barra, y en hora pico eso es un plato olvidado.
 *
 * **La frontera del PIN está en «comandado», no en el monto.** Quitar un item que nadie preparó es borrarlo, sin motivo
 * ni autorización, porque no ocurrió nada. Quitar uno que la cocina ya tiene exige motivo, PIN de un superior y decir
 * qué se hizo con la comida. Lo que se protege no es el valor: es que alguien ya trabajó.
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
    $almacen = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    // El alta NO crea áreas de preparación (D11: las configura el negocio), así que aquí se crean las dos que el ruteo
    // necesita. Lo aprendí en el paso 2 buscando un `firstOrFail()` que no encontraba nada.
    $this->cocina = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COCINA',
        'name' => 'Cocina',
    ]);

    $this->barra = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'BARRA',
        'name' => 'Barra',
    ]);

    $bebidas = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);

    // Una categoría de segundo nivel, para probar que el ruteo ASCIENDE cuando la hija no tiene regla propia.
    $this->antojitos = ArticleCategory::create([
        'name' => 'Antojitos',
        'parent_id' => $alimentos->id,
        'level' => 2,
    ]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $bebidas->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '45.00',
        'is_available_in_pos' => true,
    ]);

    $this->tacos = Article::create([
        'name' => 'Tacos de canasta',
        'category_id' => $this->antojitos->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '60.00',
        'is_available_in_pos' => true,
    ]);

    // Una categoría SIN regla de ruteo. Mi primera versión de esta prueba usaba un artículo sin categoría y el
    // invariante del catálogo la rechazó: un vendible siempre lleva categoría, porque el POS agrupa la pantalla por
    // ella. Y el caso real es justamente éste — no «un artículo huérfano», sino una categoría que nadie ruteó porque
    // nadie tiene que preparar lo que hay dentro.
    $sinRuteo = ArticleCategory::create(['name' => 'De la nevera', 'level' => 1]);

    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $sinRuteo->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '50.00',
        'is_available_in_pos' => true,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $bebidas->id,
        'preparation_area_id' => $this->barra->id,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $alimentos->id,
        'preparation_area_id' => $this->cocina->id,
    ]);

    app(TenantContext::class)->forget();

    /** Abre una cuenta de barra y devuelve su ULID. */
    $this->abrir = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Mesa de prueba',
        ])
        ->assertCreated()
        ->json('data.ulid');

    /** Abre un pedido para llevar y devuelve su ULID. */
    $this->abrirTakeout = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'takeout' => true,
        ])
        ->assertCreated()
        ->json('data.ulid');

    /**
     * Captura una orden con las líneas dadas y devuelve el ULID de la orden.
     *
     * @param  list<array{0: Article, 1?: string}>  $lineas
     */
    $this->capturar = function (string $cuenta, array $lineas): string {
        $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => array_map(fn (array $l): array => [
                    'article_ulid' => $l[0]->ulid,
                    'quantity' => $l[1] ?? '1',
                ], $lineas),
            ])
            ->assertCreated();

        // La última orden de la cuenta es la que se acaba de crear.
        $ordenes = $respuesta->json('data.orders');

        return end($ordenes)['ulid'];
    };

    /** Comanda una orden. */
    $this->comandar = fn (string $cuenta, string $orden) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command");
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// El ruteo
// ---------------------------------------------------------------------------

it('rutea por categoría y ASCIENDE a la categoría padre', function () {
    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [[$this->cafe], [$this->tacos], [$this->cerveza]]);

    app(TenantContext::class)->set($this->tenant->id);

    $porNombre = PosOrderItem::query()->get()->keyBy('article_name');

    // Café: su categoría «Bebidas» tiene regla propia.
    expect((int) $porNombre['Café americano']->preparation_area_id)->toBe($this->barra->id);

    // Tacos: su categoría «Antojitos» NO tiene regla; se hereda la de su padre «Alimentos». Es el caso que hace la
    // configuración soportable — un negocio con cuarenta subcategorías no debería declarar cuarenta reglas.
    expect((int) $porNombre['Tacos de canasta']->preparation_area_id)->toBe($this->cocina->id);

    // Cerveza: su categoría no tiene regla y no hay padre del que heredar, así que no hay área. Y es legítimo:
    // nadie tiene que prepararla.
    expect($porNombre['Cerveza']->preparation_area_id)->toBeNull();
});

it('la regla del ARTÍCULO gana sobre la de su categoría', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Los tacos van a la parrilla aunque «Alimentos» diga cocina.
    $almacen = Warehouse::query()->where('branch_id', $this->branch->id)->sole();
    $parrilla = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'PARRILLA',
        'name' => 'Parrilla',
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_id' => $this->tacos->id,
        'preparation_area_id' => $parrilla->id,
    ]);

    app(TenantContext::class)->forget();

    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [[$this->tacos]]);

    app(TenantContext::class)->set($this->tenant->id);

    expect((int) PosOrderItem::query()->sole()->preparation_area_id)->toBe($parrilla->id);
});

it('el área se CONGELA al capturar y no cambia si el ruteo cambia', function () {
    // Es la misma decisión que el precio (D240). Si el área se resolviera al comandar, cambiar una regla a media tarde
    // partiría una cuenta abierta entre dos áreas: el mismo plato iría a la cocina si se capturó antes y a la barra si
    // se capturó después.
    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [[$this->cafe]]);

    app(TenantContext::class)->set($this->tenant->id);

    PosAreaRoute::query()
        ->where('preparation_area_id', $this->barra->id)
        ->update(['preparation_area_id' => $this->cocina->id]);

    expect((int) PosOrderItem::query()->sole()->preparation_area_id)->toBe($this->barra->id);
});

// ---------------------------------------------------------------------------
// Comandar
// ---------------------------------------------------------------------------

it('emite UNA comanda por área', function () {
    Event::fake([PosOrderCommanded::class]);

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe], [$this->tacos]]);

    $respuesta = ($this->comandar)($cuenta, $orden)->assertCreated();

    // Dos papeles: barra y cocina.
    expect($respuesta->json('data'))->toHaveCount(2);

    $areas = collect($respuesta->json('data'))->pluck('preparation_area.code')->sort()->values()->all();
    expect($areas)->toBe(['BARRA', 'COCINA']);

    // Y un evento por área, no uno por orden: quien imprime no debería tener que volver a agrupar lo que el POS ya
    // agrupó.
    Event::assertDispatchedTimes(PosOrderCommanded::class, 2);

    app(TenantContext::class)->set($this->tenant->id);

    expect(PosOrderItem::query()->pluck('status')->unique()->all())
        ->toBe([PosOrderItemStatus::Commanded]);
});

it('los items SIN área pasan a comandado y no producen papel', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cerveza]]);

    // Ni un ticket: no hay impresora a la que mandarlo ni nadie esperándolo.
    ($this->comandar)($cuenta, $orden)->assertCreated()->assertJsonCount(0, 'data');

    app(TenantContext::class)->set($this->tenant->id);

    // Pero el item SÍ avanza. El hecho que marca «comandado» no es «la cocina lo recibió», es «esto ya salió y el
    // cliente lo tiene»: dejarlo en «capturado» haría que quitarlo de la cuenta fuera un borrado sin rastro, y lo que
    // pasó es que alguien se llevó una cerveza.
    expect(PosOrderItem::query()->sole()->status)->toBe(PosOrderItemStatus::Commanded);
});

it('comandar dos veces NO duplica comandas', function () {
    // Importa porque la red de un restaurante se cae, y el mesero vuelve a picar el botón cuando no ve confirmación —
    // que es exactamente el momento en que un sistema mal hecho manda la comida dos veces.
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);

    ($this->comandar)($cuenta, $orden)->assertCreated()->assertJsonCount(1, 'data');

    // La segunda vez no hay nada pendiente que tomar, y la respuesta lo dice sin fingir un error.
    ($this->comandar)($cuenta, $orden)->assertCreated()->assertJsonCount(0, 'data');

    app(TenantContext::class)->set($this->tenant->id);

    expect(PosTicket::query()->commands()->count())->toBe(1);
});

it('comandar mueve la versión de la cuenta', function () {
    // Comandar no cambia el total —los items ya estaban contados— pero sí cambia lo que la cuenta contiene. Sin mover la
    // versión, quien la tenía en pantalla desde antes podría cobrar creyendo que nada se comandó, y el candado optimista
    // no lo detendría.
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);

    $antes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.version');

    ($this->comandar)($cuenta, $orden)->assertCreated();

    $despues = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.version');

    expect($despues)->toBeGreaterThan($antes);
});

it('no se comanda una orden de otra cuenta', function () {
    $primera = ($this->abrir)();
    $orden = ($this->capturar)($primera, [[$this->cafe]]);
    $segunda = ($this->abrir)();

    ($this->comandar)($segunda, $orden)->assertStatus(409);
});

// ---------------------------------------------------------------------------
// La frontera del PIN
// ---------------------------------------------------------------------------

it('cancelar un item SIN comandar lo borra, sin motivo ni PIN', function () {
    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [[$this->cafe], [$this->tacos]]);

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->where('article_name', 'Café americano')->sole();
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", ['item_ulids' => [$item->ulid]])
        ->assertOk();

    // No queda rastro en la cuenta porque no ocurrió nada: la línea desaparece y el total se recalcula.
    expect($respuesta->json('data.items'))->toHaveCount(1);
    expect($respuesta->json('data.totals.total'))->toBe('60.00');

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosOrderItem::query()->count())->toBe(1);
});

it('cancelar un item COMANDADO exige PIN, y responde 409 con el permiso que falta', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
            'item_ulids' => [$item->ulid],
            'reason' => 'Al cliente no le gustó',
            'destination' => 'waste',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'pos.items.cancel_commanded');
});

it('cancelar un item COMANDADO exige motivo Y destino', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    // Sin motivo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
            'item_ulids' => [$item->ulid],
            'destination' => 'waste',
        ])
        ->assertStatus(409);

    // Sin destino. De él depende que el inventario registre una merma o devuelva el producto, así que adivinarlo movería
    // existencias a ciegas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
            'item_ulids' => [$item->ulid],
            'reason' => 'Al cliente no le gustó',
        ])
        ->assertStatus(409);
});

it('con PIN, cancela el item, emite comanda de cancelación y registra al AUTORIZADOR', function () {
    Event::fake([PosItemsCancelled::class]);

    $gerente = pinDeGerente($this->tenant->id, $this->branch->id);

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'pos.items.cancel_commanded',
        ])
        ->assertCreated()
        ->json('data.token');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
            'item_ulids' => [$item->ulid],
            'reason' => 'Al cliente no le gustó',
            'destination' => 'waste',
            'authorization_token' => $token,
        ])
        ->assertOk();

    // La línea sigue ahí, cancelada: hubo comida hecha y eso no se borra.
    expect($respuesta->json('data.items'))->toHaveCount(1);
    expect($respuesta->json('data.items.0.status'))->toBe('cancelled');

    // Y ya no cuenta para el total.
    expect($respuesta->json('data.totals.total'))->toBe('0.00');

    app(TenantContext::class)->set($this->tenant->id);

    $cancelado = PosOrderItem::query()->sole();

    // Quien queda registrado es el GERENTE que autorizó, no el dueño que tocó la pantalla (§6.3).
    expect((int) $cancelado->cancelled_by_membership_id)->toBe($gerente->id);
    expect($cancelado->cancellation_destination)->toBe('waste');

    // El papel para la cocina: «lo de hace diez minutos, ya no».
    $cancelacion = PosTicket::query()->where('kind', PosTicketKind::CommandCancellation->value)->sole();
    expect((int) $cancelacion->preparation_area_id)->toBe($this->barra->id);

    Event::assertDispatched(PosItemsCancelled::class, function (PosItemsCancelled $evento): bool {
        return $evento->items[0]['destination'] === 'waste'
            && $evento->items[0]['article_name'] === 'Café americano';
    });
});

it('una autorización no sirve para dos cancelaciones', function () {
    pinDeGerente($this->tenant->id, $this->branch->id);

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe], [$this->tacos]]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $items = PosOrderItem::query()->get();
    app(TenantContext::class)->forget();

    $token = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => 'pos.items.cancel_commanded',
        ])
        ->json('data.token');

    $cancelar = fn (string $ulid) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
            'item_ulids' => [$ulid],
            'reason' => 'Al cliente no le gustó',
            'destination' => 'restock',
            'authorization_token' => $token,
        ]);

    $cancelar((string) $items[0]->ulid)->assertOk();

    // La concesión se gastó. Sin esto, un PIN pedido una vez autorizaría cancelaciones toda la noche.
    //
    // Responde **422** y no 409, que es lo que yo esperaba al escribir la prueba: una concesión gastada es el mismo
    // caso que un PIN incorrecto —lo que mandaste no sirve, pide otro— y así responde `PinAuthorizationFailed` en todo
    // el sistema desde ADR-008. El 409 es para «el estado del negocio no admite la acción», que no es esto.
    $cancelar((string) $items[1]->ulid)->assertStatus(422);
});

it('no se cancela dos veces el mismo item', function () {
    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [[$this->cafe]]);

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", ['item_ulids' => [$item->ulid]])
        ->assertOk();

    // Ya no existe: se borró porque nadie lo había preparado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", ['item_ulids' => [$item->ulid]])
        ->assertStatus(422);
});

it('no se cancelan items de otra cuenta', function () {
    $primera = ($this->abrir)();
    ($this->capturar)($primera, [[$this->cafe]]);

    app(TenantContext::class)->set($this->tenant->id);
    $ajeno = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    $segunda = ($this->abrir)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$segunda}/items/cancel", ['item_ulids' => [$ajeno->ulid]])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// La máquina de estados
// ---------------------------------------------------------------------------

it('el servidor publica las transiciones permitidas del item', function () {
    // El cliente no lleva su propia copia de la máquina de estados: dos pantallas con dos copias acaban discrepando, y
    // la que discrepa es siempre la que el usuario está mirando.
    expect(PosOrderItemStatus::Captured->allowedNext())
        ->toBe([PosOrderItemStatus::Commanded, PosOrderItemStatus::Cancelled]);

    // Servido todavía se puede cancelar: el plato llegó mal y se retira. Es justo el caso en que el destino `waste`
    // importa.
    expect(PosOrderItemStatus::Served->allowedNext())->toBe([PosOrderItemStatus::Cancelled]);

    // Y de cancelado no se sale. Corregir una cancelación es capturar la línea otra vez, no revivirla.
    expect(PosOrderItemStatus::Cancelled->allowedNext())->toBe([]);

    // Retroceder lo que la cocina ya movió sería reescribir lo que pasó.
    expect(PosOrderItemStatus::Preparing->canTransitionTo(PosOrderItemStatus::Commanded))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Reglas de ruteo: la configuración
// ---------------------------------------------------------------------------

it('una regla no apunta a un artículo Y a una categoría a la vez', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-area-routes', [
            'branch_ulid' => $this->branch->ulid,
            'article_ulid' => $this->cafe->ulid,
            'article_category_ulid' => $this->antojitos->ulid,
            'preparation_area_ulid' => $this->cocina->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['article_ulid']]);
});

it('una regla no apunta a un área de OTRA sucursal', function () {
    // Es la razón de que esta tabla exista en lugar de una columna en `articles` (D240): si se pudiera cruzar, las
    // comandas de una sucursal saldrían por la impresora de otra y en la primera nadie sabría por qué la cocina no
    // recibe nada.
    app(TenantContext::class)->set($this->tenant->id);

    $otra = \App\Modules\Organization\Infrastructure\Models\Branch::create([
        'name' => 'Polanco',
        'code' => 'POLA',
    ]);

    // `kind` es obligatorio y el CHECK lo ata a `branch_id`: un almacén central no tiene sucursal y uno de sucursal
    // sí. Lo omití y MySQL 8 —que sí aplica los CHECK— lo rechazó.
    $almacen = Warehouse::create([
        'branch_id' => $otra->id,
        'kind' => 'branch',
        'code' => 'ALM-POLA',
        'name' => 'Almacén Polanco',
    ]);

    $areaAjena = PreparationArea::create([
        'branch_id' => $otra->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COCINA',
        'name' => 'Cocina Polanco',
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-area-routes', [
            'branch_ulid' => $this->branch->ulid,
            'article_category_ulid' => $this->antojitos->ulid,
            'preparation_area_ulid' => $areaAjena->ulid,
        ])
        ->assertStatus(422);
});

it('lista, crea y borra reglas de ruteo', function () {
    $creada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-area-routes', [
            'branch_ulid' => $this->branch->ulid,
            'article_ulid' => $this->cafe->ulid,
            'preparation_area_ulid' => $this->cocina->ulid,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_article_override', true)
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/pos-area-routes?branch='.$this->branch->ulid)
        ->assertOk()
        // Las dos del `beforeEach` más la que se acaba de crear.
        ->assertJsonCount(3, 'data');

    // Se BORRA, en un sistema donde casi nada se borra: no es un hecho, es configuración. Los items ya capturados
    // llevan su área congelada, así que quitar la regla no reescribe ninguna comanda ya emitida.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/pos-area-routes/{$creada}")
        ->assertStatus(204);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/pos-area-routes')
        ->assertJsonCount(2, 'data');
});

// ---------------------------------------------------------------------------
// Los papeles
// ---------------------------------------------------------------------------

it('lista y muestra lo que se imprimió, con el nombre congelado', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe, '2']]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    // El catálogo cambia DESPUÉS de comandar…
    app(TenantContext::class)->set($this->tenant->id);
    $this->cafe->update(['name' => 'Café de olla']);
    app(TenantContext::class)->forget();

    $ticket = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/pos-tickets?area='.$this->barra->ulid)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-tickets/{$ticket}")
        ->assertOk()
        // …y la comanda sigue diciendo lo que decía cuando salió. Una comanda reimpresa tiene que ser el mismo papel.
        ->assertJsonPath('data.items.0.article_name', 'Café americano')
        ->assertJsonPath('data.items.0.quantity', '2.0000')
        // Una comanda no folia: es un papel de cocina, no un documento con valor.
        ->assertJsonPath('data.folio', null)
        ->assertJsonPath('data.reprint_count', 0);
});

it('reimprimir cuenta las veces y vuelve a despachar el evento', function () {
    Event::fake([PosOrderCommanded::class]);

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);
    $ticket = ($this->comandar)($cuenta, $orden)->assertCreated()->json('data.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-tickets/{$ticket}/reprint")
        ->assertOk()
        // Un papel que sale dos veces de la cocina es comida preparada dos veces si nadie se da cuenta. Por eso se
        // cuenta y por eso es `POST`.
        ->assertJsonPath('data.reprint_count', 1);

    Event::assertDispatchedTimes(PosOrderCommanded::class, 2);
});

// ---------------------------------------------------------------------------
// Autorización y aislamiento
// ---------------------------------------------------------------------------

it('sin permiso de comandar, 403', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->sole();

    $usuario = User::factory()->create();

    $sinPermiso = TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'X001',
        'has_all_branches' => true,
        'default_role_id' => $cajero->id,
    ]);

    // `syncRoles` es del USUARIO, no de la membresía — y el rol ACTIVO sale de `default_role_id` (D9), porque Spatie
    // suma roles y aquí opera uno solo. Las dos cosas hacen falta: sin el rol en el usuario no hay permisos, y sin
    // `default_role_id` el servicio de contexto no sabe con cuál operar.
    $usuario->syncRoles([$cajero]);
    $cajero->revokePermissionTo('pos.orders.send_to_area');

    app(TenantContext::class)->forget();

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command")
        ->assertStatus(403);
});

it('las comandas de un negocio son invisibles para otro', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->cafe]]);
    ($this->comandar)($cuenta, $orden)->assertCreated();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'otro@ajena.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Pérez',
        plainPassword: 'secreto-largo-456',
    );

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/pos-tickets')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/pos-area-routes')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Cobro al ordenar (§6.3, punto 5): un para llevar `on_order` no sale a cocina sin pagar.
// La ENTREGA nunca depende del pago (D269); esto gobierna sólo el momento de comandar.
// ---------------------------------------------------------------------------

it('para llevar «al ordenar» NO se comanda sin pagar', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForBranch('pos.takeout_payment_timing', $this->branch->id, 'on_order'),
    );

    $cuenta = ($this->abrirTakeout)();
    $orden = ($this->capturar)($cuenta, [[$this->tacos]]);

    ($this->comandar)($cuenta, $orden)->assertStatus(409);

    // Y NADA salió a cocina: los items siguen capturados y no se emitió comanda.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(PosOrderItem::query()->where('status', PosOrderItemStatus::Commanded->value)->count())->toBe(0);
        expect(PosTicket::query()->where('kind', PosTicketKind::Command->value)->count())->toBe(0);
    });
});

it('para llevar «al ordenar» YA pagado sí se comanda', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForBranch('pos.takeout_payment_timing', $this->branch->id, 'on_order'),
    );

    $cuenta = ($this->abrirTakeout)();
    $orden = ($this->capturar)($cuenta, [[$this->tacos]]);

    // Se salda la cuenta directamente para aislar el gate del comandar de la maquinaria de cobro (sesión de caja,
    // método de pago): lo que el gate mira es `paid_total >= total`, y eso es lo que se prepara aquí.
    app(TenantContext::class)->runFor($this->tenant->id, function () use ($cuenta): void {
        $c = PosAccount::query()->where('ulid', $cuenta)->sole();
        $c->update(['paid_total' => $c->total]);
    });

    ($this->comandar)($cuenta, $orden)->assertCreated();
});

it('para llevar «al recoger» (default) se comanda sin pagar', function () {
    // `on_pickup` es el default: se prepara y se cobra al recoger. Sin bloqueo al comandar.
    $cuenta = ($this->abrirTakeout)();
    $orden = ($this->capturar)($cuenta, [[$this->tacos]]);

    ($this->comandar)($cuenta, $orden)->assertCreated();
});

it('el bloqueo es SÓLO para llevar: una cuenta de mesa «al ordenar» se comanda sin pagar', function () {
    // El ajuste es por sucursal, pero el gate mira `isTakeout()`: una cuenta de mesa/barra no se toca aunque la
    // sucursal cobre al ordenar —comer aquí se cobra al final, siempre.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForBranch('pos.takeout_payment_timing', $this->branch->id, 'on_order'),
    );

    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [[$this->tacos]]);

    ($this->comandar)($cuenta, $orden)->assertCreated();
});

/**
 * Un gerente con PIN, para autorizar cancelaciones.
 *
 * Devuelve la membresía porque las pruebas comprueban que la bitácora y la línea nombran al AUTORIZADOR y no a quien
 * tocó la pantalla.
 */
function pinDeGerente(int $tenantId, int $branchId): TenantMembership
{
    return app(\App\Modules\Shared\Domain\Tenancy\TenantContext::class)->runFor($tenantId, function () use ($branchId): TenantMembership {
        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->sole();

        $usuario = User::factory()->create();

        $gerente = TenantMembership::factory()->create([
            'user_id' => $usuario->id,
            'employee_code' => 'G001',
            'has_all_branches' => true,
            'default_role_id' => $rol->id,
        ]);

        $usuario->syncRoles([$rol]);

        app(\App\Modules\Identity\Application\ManageMembershipPin::class)->set($gerente, '1111');

        return $gerente;
    });
}

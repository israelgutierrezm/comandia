<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Shared\Domain\Contracts\LiveServiceProbe;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LAS MESAS EN OPERACIÓN (§6.4, paso 13)
 *
 * ## Lo que este paso cerró
 *
 * Liberar una mesa a mano **no comprobaba si tenía cuentas vivas**. El propio controlador lo tenía anotado desde el paso
 * 5: «lo comprobará el POS cuando existan las cuentas». Sin la comprobación, liberar una mesa con una cuenta abierta la
 * deja huérfana — el siguiente cliente se sienta ahí, el mesero abre otra cuenta, y las dos conviven sobre la misma mesa
 * hasta que alguien cobra una y se olvida de la otra.
 *
 * La respuesta la sabe `Pos` y la pregunta `Floor`, que no lo conoce. Va por un contrato del KERNEL con la dependencia
 * invertida: es el mismo patrón de D231 con los eventos, aplicado a una **pregunta** en lugar de a un anuncio.
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

    $plan = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Planta baja', 'is_default' => true]);
    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón']);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id, 'floor_zone_id' => $zona->id, 'code' => 'M1', 'seats' => 4,
    ]);

    $this->delFondo = RestaurantTable::create([
        'branch_id' => $this->branch->id, 'floor_zone_id' => $zona->id, 'code' => 'M9', 'seats' => 6,
    ]);

    app(TenantContext::class)->forget();

    $this->abrirEnMesa = fn (RestaurantTable $mesa): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $mesa->ulid])
        ->assertCreated()
        ->json('data.ulid');
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Liberar a mano
// ---------------------------------------------------------------------------

it('NO se libera a mano una mesa con una cuenta viva', function () {
    ($this->abrirEnMesa)($this->mesa);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$this->mesa->ulid}/free")
        ->assertStatus(409);

    // Y sigue ocupada: el rechazo no puede dejarla a medias.
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Occupied);
});

it('sí se libera una mesa ocupada POR ERROR, sin cuentas', function () {
    // Es una de las dos transiciones manuales que §6.4 admite: una mesa que quedó ocupada sin que nadie se sentara.
    app(TenantContext::class)->set($this->tenant->id);
    $this->mesa->update(['status' => TableStatus::Occupied]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$this->mesa->ulid}/free")
        ->assertOk()
        ->assertJsonPath('data.status', 'free');
});

it('marcar limpia una mesa que espera limpieza es la otra transición manual', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $this->mesa->update(['status' => TableStatus::NeedsCleaning]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$this->mesa->ulid}/free")
        ->assertOk()
        ->assertJsonPath('data.status', 'free');
});

it('una cuenta PAGADA ya no cuenta como servicio en curso', function () {
    // Una mesa cuyas cuentas están todas pagadas no le debe nada a nadie. Si contara, una mesa liberada por el cobro no
    // se podría volver a liberar a mano nunca — y quedaría atrapada si algo la dejó ocupada después.
    $cuenta = ($this->abrirEnMesa)($this->mesa);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/cancel", ['reason' => 'El cliente se fue'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(app(LiveServiceProbe::class)->tableHasLiveService((int) $this->mesa->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Mover la cuenta de mesa
// ---------------------------------------------------------------------------

it('mueve la cuenta a otra mesa: ocupa la nueva y libera la vieja', function () {
    // «Nos pasamos a la mesa del fondo» ocurre en cada servicio, y hasta este paso la única salida era cancelar la
    // cuenta y volver a capturar todo — que además pide PIN por cada item ya comandado.
    $cuenta = ($this->abrirEnMesa)($this->mesa);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '2']],
        ])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/table", ['table_ulid' => $this->delFondo->ulid])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Mesa M9')
        ->assertJsonPath('data.table.code', 'M9')
        // Los items se van con ella: es la misma cuenta.
        ->assertJsonPath('data.totals.total', '100.00');

    expect($this->delFondo->refresh()->status)->toBe(TableStatus::Occupied);
    expect($this->mesa->refresh()->status)->toBe(TableStatus::Free);
});

it('asigna mesa a una cuenta de barra y le quita la etiqueta', function () {
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Señor de lentes',
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/table", ['table_ulid' => $this->mesa->ulid])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Mesa M1')
        // La etiqueta se va: conservarla haría que `displayName()` tuviera que elegir entre dos identidades, que es lo
        // que el invariante del paso 7 impide desde el alta.
        ->assertJsonPath('data.label', null);
});

it('no se mueve a una mesa OCUPADA', function () {
    ($this->abrirEnMesa)($this->mesa);
    $cuenta = ($this->abrirEnMesa)($this->delFondo);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/table", ['table_ulid' => $this->mesa->ulid])
        ->assertStatus(422);

    // Y la mesa de origen NO se liberó: la transacción deshace todo antes de soltar nada.
    expect($this->delFondo->refresh()->status)->toBe(TableStatus::Occupied);
});

it('no se mueve a la mesa en la que ya está', function () {
    $cuenta = ($this->abrirEnMesa)($this->mesa);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/table", ['table_ulid' => $this->mesa->ulid])
        ->assertStatus(409);
});

it('con el estado de limpieza encendido, la mesa que se deja queda POR LIMPIAR', function () {
    // Qué significa liberar lo decide el salón, no el POS (D239): mover una cuenta usa la misma puerta que cobrarla.
    app(TenantContext::class)->set($this->tenant->id);
    app(Settings::class)->setForTenant('floor.use_cleaning_state', true);
    app(TenantContext::class)->forget();

    $cuenta = ($this->abrirEnMesa)($this->mesa);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/table", ['table_ulid' => $this->delFondo->ulid])
        ->assertOk();

    expect($this->mesa->refresh()->status)->toBe(TableStatus::NeedsCleaning);
});

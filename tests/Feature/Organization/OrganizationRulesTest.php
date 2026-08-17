<?php

declare(strict_types=1);

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * Reglas propias de almacenes, áreas de preparación y terminales.
 *
 * No repite la superficie CRUD —eso está en `BranchCrudTest`— sino las reglas que sólo tienen
 * estas entidades, que son las que de verdad pueden salir mal: la coherencia central/sucursal
 * de D11, la alcanzabilidad del almacén desde la sucursal del área, y la unicidad de código
 * por sucursal.
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

    // El alta crea la sucursal con su almacén por defecto (§1: un tenant que no configura nada
    // obtiene un restaurante funcional).
    $this->warehouse = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Warehouse => $this->branch->defaultWarehouse
    );

    $this->otherBranch = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Branch => Branch::factory()->create(['code' => 'SUR', 'name' => 'Sur'])
    );

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Almacenes (D11)
// ---------------------------------------------------------------------------

it('el alta deja una sucursal con su almacén por defecto', function () {
    expect($this->warehouse)->not->toBeNull();
    expect($this->warehouse->isCentral())->toBeFalse();
    expect($this->warehouse->branch_id)->toBe($this->branch->id);
});

it('crea un almacén central sin sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/warehouses', [
            'code' => 'central',
            'name' => 'Almacén central',
            'kind' => 'central',
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'central')
        ->assertJsonPath('data.is_central', true);
});

it('rechaza un almacén central CON sucursal', function () {
    // La misma condición que el CHECK de la base, expresada para el usuario: un almacén central
    // mal marcado surtiría a todas las sucursales sin que nadie lo hubiera decidido.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/warehouses', [
            'code' => 'MALO',
            'name' => 'Central con sucursal',
            'kind' => 'central',
            'branch_ulid' => $this->branch->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.branch_ulid.0', 'Un almacén central no pertenece a ninguna sucursal: surte a todas.');
});

it('rechaza un almacén de sucursal SIN sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/warehouses', [
            'code' => 'MALO2',
            'name' => 'De sucursal sin sucursal',
            'kind' => 'branch',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.branch_ulid.0', 'Un almacén de sucursal necesita indicar a qué sucursal pertenece.');
});

it('PROHÍBE convertir un almacén de sucursal en central', function () {
    // Reinterpretaría todo su histórico de existencias, y las áreas que consumen de él
    // empezarían a descontar de otro sitio sin que nadie lo pidiera.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/warehouses/{$this->warehouse->ulid}", ['kind' => 'central'])
        ->assertStatus(422)
        ->assertJsonPath('errors.kind.0', 'El tipo de almacén no se puede cambiar: reinterpretaría todo su histórico de existencias. Da de baja el almacén y crea otro.');
});

it('no da de baja un almacén del que consume un área activa', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => PreparationArea::factory()->create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'COC',
        'name' => 'Cocina',
    ]));

    // Desactivarlo sin más dejaría al área descontando de un almacén inactivo, y el descuento
    // por receta corre en la cola `critical`: el fallo aparecería como una existencia
    // incorrecta y no como un error visible.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/warehouses/{$this->warehouse->ulid}/archive")
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');
});

// ---------------------------------------------------------------------------
// Áreas de preparación (§3, D11)
// ---------------------------------------------------------------------------

it('crea un área que descuenta del almacén de su sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/preparation-areas', [
            'branch_ulid' => $this->branch->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'code' => 'coc',
            'name' => 'Cocina',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'COC')
        ->assertJsonPath('data.warehouse.ulid', $this->warehouse->ulid);
});

it('acepta que un área descuente de un almacén CENTRAL', function () {
    // El central surte a todas las sucursales (D11), así que es alcanzable desde cualquiera.
    $central = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Warehouse => Warehouse::factory()->central()->create(['code' => 'CEN-ALM'])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/preparation-areas', [
            'branch_ulid' => $this->branch->ulid,
            'warehouse_ulid' => $central->ulid,
            'code' => 'BAR',
            'name' => 'Barra',
        ])
        ->assertCreated();
});

it('RECHAZA un almacén de otra sucursal', function () {
    // Sin esta comprobación, la cocina de Centro quedaría descontando del almacén de Sur, y el
    // error se manifestaría como existencias mal en dos sitios a la vez.
    $ajeno = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Warehouse => Warehouse::factory()->create([
            'branch_id' => $this->otherBranch->id,
            'code' => 'SUR-ALM',
        ])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/preparation-areas', [
            'branch_ulid' => $this->branch->ulid,
            'warehouse_ulid' => $ajeno->ulid,
            'code' => 'PAR',
            'name' => 'Parrilla',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.warehouse_ulid.0', 'Ese almacén no es alcanzable desde esta sucursal: debe ser un almacén de la misma sucursal o un almacén central.');
});

it('SÍ permite cambiar de qué almacén descuenta un área', function () {
    // Es el ajuste que D11 prevé cuando el tenant pasa de "un almacén por sucursal" a "consumo
    // fino por área".
    $area = app(TenantContext::class)->runFor($this->tenant->id, fn (): PreparationArea => PreparationArea::factory()->create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'COC',
    ]));

    $nuevo = app(TenantContext::class)->runFor($this->tenant->id, fn (): Warehouse => Warehouse::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'MTZ-BAR',
    ]));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['warehouse_ulid' => $nuevo->ulid])
        ->assertOk()
        ->assertJsonPath('data.warehouse.ulid', $nuevo->ulid);
});

it('PROHÍBE mover un área a otra sucursal', function () {
    $area = app(TenantContext::class)->runFor($this->tenant->id, fn (): PreparationArea => PreparationArea::factory()->create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'COC',
    ]));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['branch_ulid' => $this->otherBranch->ulid])
        ->assertStatus(422)
        ->assertJsonPath('errors.branch_ulid.0', 'Un área no cambia de sucursal: es destino de comandas ya emitidas. Da de baja el área y crea otra.');
});

it('rechaza dos áreas con el mismo código en la misma sucursal', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => PreparationArea::factory()->create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'COC',
    ]));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/preparation-areas', [
            'branch_ulid' => $this->branch->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'code' => 'COC',
            'name' => 'Otra cocina',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Ya existe un área con ese código en esta sucursal.');
});

it('lista las áreas en el orden que el tenant definió', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        PreparationArea::factory()->create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id,
            'code' => 'POS', 'name' => 'Postres', 'sort_order' => 3,
        ]);
        PreparationArea::factory()->create([
            'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id,
            'code' => 'COC', 'name' => 'Cocina', 'sort_order' => 1,
        ]);
    });

    // Por `sort_order` y no alfabético: el orden de las áreas es una decisión del tenant.
    $nombres = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/preparation-areas')
        ->json('data.*.name');

    expect($nombres)->toBe(['Cocina', 'Postres']);
});

// ---------------------------------------------------------------------------
// Terminales
// ---------------------------------------------------------------------------

it('permite el mismo código de terminal en sucursales distintas', function () {
    // Dos sucursales pueden tener su "Caja 1", y prohibirlo sería una regla inventada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/terminals', [
            'branch_ulid' => $this->branch->ulid, 'code' => 'CAJA1', 'name' => 'Caja 1',
        ])->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/terminals', [
            'branch_ulid' => $this->otherBranch->ulid, 'code' => 'CAJA1', 'name' => 'Caja 1',
        ])->assertCreated();
});

it('rechaza dos terminales con el mismo código en la misma sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/terminals', [
            'branch_ulid' => $this->branch->ulid, 'code' => 'CAJA1', 'name' => 'Caja 1',
        ])->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/terminals', [
            'branch_ulid' => $this->branch->ulid, 'code' => 'CAJA1', 'name' => 'Duplicada',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Ya existe una terminal con ese código en esta sucursal.');
});

it('una terminal dada de baja deja de valer para el header X-Terminal', function () {
    $terminal = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Terminal => Terminal::factory()->create(['branch_id' => $this->branch->id])
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Terminal', $terminal->ulid)
        ->getJson('/api/v1/context')
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/terminals/{$terminal->ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    // El efecto es inmediato en la siguiente petición del POS.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Terminal', $terminal->ulid)
        ->getJson('/api/v1/context')
        ->assertForbidden();
});

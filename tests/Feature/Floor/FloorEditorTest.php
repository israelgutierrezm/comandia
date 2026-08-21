<?php

declare(strict_types=1);

use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL EDITOR DEL SALÓN: ZONAS, GUARDADO POR LOTE Y RETIRO DE MESAS (Iteración 5, pasos 2–4)
 *
 * ## Lo que estas pruebas fijan, y por qué cada una
 *
 * **El guardado es por LOTE y con versión.** Doce mesas movidas son un acto: guardarlas de una en una deja el plano a
 * medias si la quinta falla, y un salón a medias describe una distribución que no existió nunca. La versión existe
 * porque dos gerentes editando a la vez se pisan sin enterarse, y el resultado no es el plano de ninguno de los dos.
 *
 * **Una zona no es una etiqueta.** La mesa PERTENECE a una zona, así que la zona es la que ata la mesa al plano: no se
 * puede borrar la última —el plano quedaría sin admitir mesas— ni una que tenga mesas dentro.
 *
 * **Retirar no es borrar.** `pos_accounts.table_id` es `RESTRICT` y debe serlo: la cuenta de anoche dice en qué mesa se
 * sentó la gente. Borrar dejaría al negocio eligiendo entre conservar su historial y ordenar su salón.
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

    $this->plan = FloorPlan::create([
        'branch_id' => $this->branch->id,
        'name' => 'Planta baja',
        'is_default' => true,
    ]);

    $this->zona = FloorZone::create(['floor_plan_id' => $this->plan->id, 'name' => 'Salón', 'sort_order' => 10]);

    $this->mesa = fn (string $code): RestaurantTable => RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $this->zona->id,
        'code' => $code,
        'seats' => 4,
    ]);

    app(TenantContext::class)->forget();

    /** La geometría de una mesa, tal como la manda el editor. */
    $this->geometria = fn (RestaurantTable $m, string $x, string $y): array => [
        'ulid' => $m->ulid,
        'x' => $x,
        'y' => $y,
        'width' => '80.00',
        'height' => '80.00',
        'rotation' => '0.00',
        'shape' => 'rectangle',
    ];
});

afterEach(fn () => app(TenantContext::class)->forget());

// --------------------------------------------------------------------- Zonas

it('una zona se crea al final de la lista', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$this->plan->ulid}/zones", ['name' => 'Terraza'])
        ->assertCreated();

    // De diez en diez, para poder insertar entre dos sin renumerar todas.
    $respuesta->assertJsonPath('data.name', 'Terraza');
    $respuesta->assertJsonPath('data.sort_order', 20);
});

it('la última zona no se puede borrar', function () {
    // Un plano sin zonas no admite mesas: quedaría inservible y habría que adivinar cómo repararlo desde la interfaz.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/floor-zones/{$this->zona->ulid}")
        ->assertStatus(409);
});

it('una zona con mesas no se puede borrar', function () {
    app(TenantContext::class)->set($this->tenant->id);
    ($this->mesa)('M1');
    $otra = FloorZone::create(['floor_plan_id' => $this->plan->id, 'name' => 'Terraza']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/floor-zones/{$this->zona->ulid}")
        ->assertStatus(409);

    // La vacía sí, porque ya no es la última.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/floor-zones/{$otra->ulid}")
        ->assertNoContent();
});

// ------------------------------------------------------- Guardado por lote

it('el salón se guarda entero y la versión sube UNA vez', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $m1 = ($this->mesa)('M1');
    $m2 = ($this->mesa)('M2');
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$this->plan->ulid}/layout", [
            'version' => 1,
            'canvas' => ['width' => '1000.00', 'height' => '600.00'],
            'tables' => [
                ($this->geometria)($m1, '100.00', '200.00'),
                ($this->geometria)($m2, '300.00', '200.00'),
            ],
        ])
        ->assertOk();

    // UNA vez, no una por mesa: es la versión del plano, no de cada figura.
    $respuesta->assertJsonPath('data.version', 2);
    $respuesta->assertJsonPath('data.canvas.width', '1000.00');

    app(TenantContext::class)->set($this->tenant->id);
    expect((string) $m1->refresh()->x)->toBe('100.00');
    expect((string) $m2->refresh()->x)->toBe('300.00');
});

it('guardar con una versión vieja responde 409 CON el plano actual', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $m1 = ($this->mesa)('M1');
    app(TenantContext::class)->forget();

    // El primero gana.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$this->plan->ulid}/layout", [
            'version' => 1,
            'tables' => [($this->geometria)($m1, '100.00', '100.00')],
        ])
        ->assertOk();

    // El segundo llega con la versión que leyó y pierde. Lo que importa es que se lleve el plano actual: con sólo un
    // mensaje tendría que recargar a ciegas y volver a arrastrar doce mesas de memoria.
    $conflicto = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$this->plan->ulid}/layout", [
            'version' => 1,
            'tables' => [($this->geometria)($m1, '900.00', '900.00')],
        ])
        ->assertStatus(409);

    $conflicto->assertJsonPath('type', 'version_conflict');
    $conflicto->assertJsonPath('current_version', 2);
    $conflicto->assertJsonPath('data.tables.0.geometry.x', '100.00');

    // Y no escribió nada: el perdedor no mueve la mesa.
    app(TenantContext::class)->set($this->tenant->id);
    expect((string) $m1->refresh()->x)->toBe('100.00');
});

it('una mesa de otro plano no se cuela en el lote', function () {
    // Los dos planos son del MISMO negocio, así que ninguna comprobación de tenant lo vería: la mesa se movería a
    // coordenadas de un salón que no es el suyo.
    app(TenantContext::class)->set($this->tenant->id);
    $otroPlan = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Terraza']);
    $otraZona = FloorZone::create(['floor_plan_id' => $otroPlan->id, 'name' => 'Exterior']);
    $ajena = RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $otraZona->id,
        'code' => 'T9',
        'seats' => 2,
    ]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$this->plan->ulid}/layout", [
            'version' => 1,
            'tables' => [($this->geometria)($ajena, '10.00', '10.00')],
        ])
        ->assertStatus(409);
});

it('una mesa de 50 metros es un error de captura, no un salón grande', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $m1 = ($this->mesa)('M1');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/floor-plans/{$this->plan->ulid}/layout", [
            'version' => 1,
            'tables' => [[
                'ulid' => $m1->ulid,
                'x' => '0.00', 'y' => '0.00',
                'width' => '9000.00', 'height' => '80.00',
                'rotation' => '0.00', 'shape' => 'rectangle',
            ]],
        ])
        ->assertStatus(422);
});

// ------------------------------------------------------------- Archivar

it('una mesa retirada deja de estar disponible y vuelve libre al restaurarla', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesa = ($this->mesa)('M1');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.is_available', false);

    // Y no se puede sentar a nadie en ella.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $mesa->ulid])
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/restore")
        ->assertOk()
        ->assertJsonPath('data.status', TableStatus::Free->value)
        ->assertJsonPath('data.is_available', true);
});

it('una mesa con cuenta abierta no se retira', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesa = ($this->mesa)('M1');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $mesa->ulid])
        ->assertCreated();

    // Retirarla la sacaría del editor con gente sentada en ella.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/archive")
        ->assertStatus(409);
});

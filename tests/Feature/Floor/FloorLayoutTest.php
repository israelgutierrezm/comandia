<?php

declare(strict_types=1);

use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Events\TablesRegrouped;
use App\Modules\Shared\Domain\Events\TableStateChanged;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Event;

/**
 * EL SALÓN: PLANOS, ZONAS Y MESAS (§6.4, D32, D34)
 *
 * ## Qué entra en esta iteración
 *
 * Saber **en qué mesa está una cuenta**, que es lo que un restaurante necesita para operar. El editor visual —arrastrar
 * mesas sobre el plano— es la Iteración 6 y exige ADR-003 más tiempo real. Las mesas se dan de alta por formulario y las
 * coordenadas nacen con valores por omisión.
 *
 * ## La unión de mesas es plana, y ahí está la decisión interesante
 *
 * `joined_to_table_id` apunta a la mesa principal. No hay uniones en cadena: con ellas, «¿de quién es esta cuenta?»
 * tendría que recorrer un árbol y al pagar habría que deshacer ramas.
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

    app(TenantContext::class)->forget();

    /** Crea una mesa lista para usar, con su plano y su zona. */
    $this->crearMesa = function (string $code, int $seats = 4, ?FloorZone $zone = null): RestaurantTable {
        return app(TenantContext::class)->runFor($this->tenant->id, function () use ($code, $seats, $zone) {
            $zone ??= ($this->zonaPorOmision)();

            return RestaurantTable::create([
                'branch_id' => $zone->plan->branch_id,
                'floor_zone_id' => $zone->id,
                'code' => $code,
                'seats' => $seats,
            ]);
        });
    };

    $this->zonaPorOmision = function (): FloorZone {
        return app(TenantContext::class)->runFor($this->tenant->id, function (): FloorZone {
            $plan = FloorPlan::query()->firstOr(fn () => FloorPlan::create([
                'branch_id' => $this->branch->id,
                'name' => 'Planta baja',
                'is_default' => true,
            ]));

            return FloorZone::query()->where('floor_plan_id', $plan->id)->firstOr(
                fn () => FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón'])
            );
        });
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Planos y zonas
// ---------------------------------------------------------------------------

it('un plano se crea con sus zonas en la misma petición', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/floor-plans', [
            'branch_ulid' => $this->branch->ulid,
            'name' => 'Planta baja',
            'zones' => ['Salón', 'Terraza', 'Barra'],
        ])
        ->assertCreated()
        // El primero de una sucursal es el de omisión: es el plano con el que abre la pantalla del salón.
        ->assertJsonPath('data.is_default', true);

    expect($respuesta->json('data.zones'))->toHaveCount(3)
        ->and(array_column($respuesta->json('data.zones'), 'name'))->toBe(['Salón', 'Terraza', 'Barra']);
});

it('un plano sin zonas se rechaza', function () {
    // Un plano sin zonas no admite mesas, así que crearlo vacío deja al usuario en un callejón. Es la misma lección que
    // el catálogo vacío de motivos de merma (D225).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/floor-plans', [
            'branch_ulid' => $this->branch->ulid,
            'name' => 'Vacío',
            'zones' => [],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['zones']]);
});

it('sólo hay un plano por omisión por sucursal, y lo impone la base', function () {
    app(TenantContext::class)->set($this->tenant->id);

    FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Uno', 'is_default' => true]);

    // Se prueba SIN pasar por la aplicación (D218): la columna generada con índice único puede estar en el diseño y no
    // en la base, y probarlo por la API sólo comprobaría la lógica del controlador.
    expect(fn () => FloorPlan::create([
        'branch_id' => $this->branch->id,
        'name' => 'Dos',
        'is_default' => true,
    ]))->toThrow(Illuminate\Database\QueryException::class);

    // Y dos NO por omisión conviven sin estorbarse: el índice único sobre una columna generada que vale `NULL` cuando
    // `is_default` es falso admite tantos nulos como haga falta.
    FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Tres', 'is_default' => false]);
    FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Cuatro', 'is_default' => false]);

    expect(FloorPlan::query()->count())->toBe(3);
});

it('cambiar el plano por omisión quita el anterior', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $uno = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Uno', 'is_default' => true]);
    $dos = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Dos', 'is_default' => false]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$dos->ulid}/default")
        ->assertOk()
        ->assertJsonPath('data.is_default', true);

    // Quitar el anterior ANTES de poner el nuevo no es cosmética: al revés choca con el índice único.
    expect($uno->refresh()->is_default)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Mesas
// ---------------------------------------------------------------------------

it('una mesa se da de alta por formulario, con coordenadas por omisión', function () {
    $zona = ($this->zonaPorOmision)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/restaurant-tables', [
            'floor_zone_ulid' => $zona->ulid,
            'code' => 'm1',
            'name' => 'Ventana',
            'seats' => 2,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'M1')
        ->assertJsonPath('data.seats', 2)
        ->assertJsonPath('data.status', 'free')
        // Las coordenadas nacen con valor por omisión: el editor visual llega en la Iteración 6, y sin ellas esa
        // iteración tendría que migrar datos de un salón ya en uso.
        ->assertJsonPath('data.geometry.shape', 'rectangle')
        ->assertJsonPath('data.geometry.x', '0.00');
});

it('el código de mesa es único en la sucursal, no en la zona', function () {
    $zona = ($this->zonaPorOmision)();

    app(TenantContext::class)->set($this->tenant->id);
    $otraZona = FloorZone::create(['floor_plan_id' => $zona->floor_plan_id, 'name' => 'Terraza']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/restaurant-tables', ['floor_zone_ulid' => $zona->ulid, 'code' => 'M1'])
        ->assertCreated();

    // «M1» tiene que ser una sola mesa para quien la nombra en voz alta: dos zonas con su M1 producirían la peor
    // confusión posible en un servicio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/restaurant-tables', ['floor_zone_ulid' => $otraZona->ulid, 'code' => 'M1'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

it('una mesa no cambia de código, ni de zona, ni de estado por formulario', function () {
    $mesa = ($this->crearMesa)('M1');

    foreach ([
        ['code' => 'M9'],
        ['floor_zone_ulid' => ($this->zonaPorOmision)()->ulid],
        // El estado lo mueve lo que pasa con las cuentas de la mesa (§6.3). Una mesa marcada «libre» a mano con una
        // cuenta abierta encima es la peor información posible para quien atiende la puerta.
        ['status' => 'occupied'],
    ] as $cuerpo) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->patchJson("/api/v1/restaurant-tables/{$mesa->ulid}", $cuerpo)
            ->assertStatus(422);
    }
});

it('el nombre, los asientos y la geometría sí se editan', function () {
    $mesa = ($this->crearMesa)('M1');

    // La geometría se acepta desde esta iteración aunque el editor llegue en la 6: dejarla fuera obligaría a tocar el
    // Form Request entonces.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/restaurant-tables/{$mesa->ulid}", [
            'name' => 'Rincón',
            'seats' => 6,
            'x' => 120.5,
            'y' => 80,
            'rotation' => 45,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Rincón')
        ->assertJsonPath('data.seats', 6)
        ->assertJsonPath('data.geometry.x', '120.50')
        ->assertJsonPath('data.geometry.rotation', '45.00');
});

// ---------------------------------------------------------------------------
// La unión de mesas (D32)
// ---------------------------------------------------------------------------

it('dos mesas se unen y suman sus asientos', function () {
    $principal = ($this->crearMesa)('M1', 4);
    $segunda = ($this->crearMesa)('M2', 4);

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", [
            'table_ulids' => [$segunda->ulid],
        ])
        ->assertOk();

    expect($respuesta->json('data.joined_tables'))->toHaveCount(1)
        // Los asientos efectivos son el dato que alguien necesita al sentar un grupo, y calcularlo en la interfaz
        // obligaría a traer las mesas unidas en cada refresco del salón.
        ->and($respuesta->json('data.effective_seats'))->toBe(8);

    // Y la mesa unida deja de estar disponible por su cuenta, aunque su estado siga diciendo «libre»: forma parte de un
    // conjunto que atiende una sola cuenta.
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/restaurant-tables?available_only=1")
        ->assertOk()
        ->json('data');

    expect(array_column($datos, 'code'))->toBe(['M1']);
});

it('una mesa no se une a sí misma', function () {
    $mesa = ($this->crearMesa)('M1');

    // Parece obvio y es el primer error que produce una interfaz que manda el ULID de la mesa seleccionada sin filtrarla
    // de la lista.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/join", ['table_ulids' => [$mesa->ulid]])
        ->assertStatus(422);
});

it('las uniones no se encadenan', function () {
    $primera = ($this->crearMesa)('M1');
    $segunda = ($this->crearMesa)('M2');
    $tercera = ($this->crearMesa)('M3');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$primera->ulid}/join", ['table_ulids' => [$segunda->ulid]])
        ->assertOk();

    // Unir la tercera a la SEGUNDA —que ya está unida— haría que «¿de quién es esta cuenta?» tuviera que recorrer un
    // árbol, y al pagar habría que deshacer ramas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$segunda->ulid}/join", ['table_ulids' => [$tercera->ulid]])
        ->assertStatus(422);

    // Unirla a la principal sí: una unión es plana, con una principal y N mesas colgando.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$primera->ulid}/join", ['table_ulids' => [$tercera->ulid]])
        ->assertOk()
        ->assertJsonCount(2, 'data.joined_tables');
});

it('una mesa con servicio en curso no se une', function () {
    $principal = ($this->crearMesa)('M1');
    $ocupada = ($this->crearMesa)('M2');

    app(TenantContext::class)->set($this->tenant->id);
    $ocupada->update(['status' => TableStatus::Occupied]);
    app(TenantContext::class)->forget();

    // Unirla movería su cuenta a otra mesa sin que nadie lo decidiera.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$ocupada->ulid]])
        ->assertStatus(422);
});

it('separar es idempotente', function () {
    $principal = ($this->crearMesa)('M1');
    $segunda = ($this->crearMesa)('M2');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$segunda->ulid]])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/separate")
        ->assertOk()
        ->assertJsonCount(0, 'data.joined_tables');

    // Separar una mesa que no tiene nada unido NO es un error, es el estado deseado. Devolver 422 ahí haría que la
    // pantalla tuviera que saber si hay unión antes de ofrecer el botón.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/separate")
        ->assertOk();

    expect($segunda->refresh()->joined_to_table_id)->toBeNull();
});

it('la unión es atómica: si una mesa falla, ninguna queda unida', function () {
    $principal = ($this->crearMesa)('M1');
    $libre = ($this->crearMesa)('M2');
    $ocupada = ($this->crearMesa)('M3');

    app(TenantContext::class)->set($this->tenant->id);
    $ocupada->update(['status' => TableStatus::Occupied]);
    app(TenantContext::class)->forget();

    // Se piden las dos: la libre pasaría y la ocupada no. Sin transacción quedaría una unida y otra suelta, con el
    // salón mostrando una unión a medias que nadie pidió.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", [
            'table_ulids' => [$libre->ulid, $ocupada->ulid],
        ])
        ->assertStatus(422);

    expect($libre->refresh()->joined_to_table_id)->toBeNull()
        ->and($ocupada->refresh()->joined_to_table_id)->toBeNull();
});

it('el invariante de la unión vive en el modelo, no sólo en el servicio', function () {
    $mesa = ($this->crearMesa)('M1');

    app(TenantContext::class)->set($this->tenant->id);

    // La unión se hace desde el POS, desde la pantalla de piso y —cuando llegue— desde la app: tres caminos, un solo
    // sitio donde vive la regla.
    expect(fn () => $mesa->update(['joined_to_table_id' => $mesa->id]))
        ->toThrow(TableInvariantException::class);
});

it('tampoco se cuelga una mesa que ya es principal de otra unión', function () {
    // La cadena hacia ABAJO: el invariante viejo sólo miraba que la principal no colgara de otra.
    $primera = ($this->crearMesa)('M1');
    $segunda = ($this->crearMesa)('M2');
    $nueva = ($this->crearMesa)('M3');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$primera->ulid}/join", ['table_ulids' => [$segunda->ulid]])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$nueva->ulid}/join", ['table_ulids' => [$primera->ulid]])
        ->assertStatus(422);

    expect($primera->refresh()->joined_to_table_id)->toBeNull()
        ->and($segunda->refresh()->joined_to_table_id)->toBe($primera->id);
});

it('sólo se unen mesas del mismo salón: otro plano u otra sucursal, no', function () {
    $principal = ($this->crearMesa)('M1');

    [$enTerraza, $enOtraSucursal] = app(TenantContext::class)->runFor($this->tenant->id, function (): array {
        $terraza = FloorPlan::create(['branch_id' => $this->branch->id, 'name' => 'Terraza']);
        $zonaTerraza = FloorZone::create(['floor_plan_id' => $terraza->id, 'name' => 'Terraza']);

        $sucursal = Branch::factory()->create(['code' => 'NTE', 'name' => 'Norte']);
        $plano = FloorPlan::create(['branch_id' => $sucursal->id, 'name' => 'Salón Norte', 'is_default' => true]);
        $zona = FloorZone::create(['floor_plan_id' => $plano->id, 'name' => 'Salón']);

        return [
            RestaurantTable::create(['branch_id' => $this->branch->id, 'floor_zone_id' => $zonaTerraza->id, 'code' => 'T1', 'seats' => 4]),
            RestaurantTable::create(['branch_id' => $sucursal->id, 'floor_zone_id' => $zona->id, 'code' => 'N1', 'seats' => 4]),
        ];
    });

    foreach ([$enTerraza, $enOtraSucursal] as $ajena) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$ajena->ulid]])
            ->assertStatus(422);

        expect($ajena->refresh()->joined_to_table_id)->toBeNull();
    }
});

it('una mesa retirada del piso no se une', function () {
    $principal = ($this->crearMesa)('M1');
    $retirada = ($this->crearMesa)('M2');

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $retirada->archive());

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$retirada->ulid]])
        ->assertStatus(422);
});

it('unir y separar avisan al piso en vivo; separar sin nada unido no avisa', function () {
    // Unir y separar no cambian el ESTADO de ninguna mesa, así que antes no avisaban: las demás terminales seguían
    // ofreciendo sentar gente en la mitad de una mesa de ocho.
    Event::fake([TablesRegrouped::class]);

    $principal = ($this->crearMesa)('M1');
    $segunda = ($this->crearMesa)('M2');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$segunda->ulid]])
        ->assertOk();

    Event::assertDispatched(TablesRegrouped::class, fn (TablesRegrouped $e): bool => $e->joined
        && $e->mainTableUlid === $principal->ulid
        && $e->tableUlids === [$segunda->ulid]
        && $e->branchUlid === $this->branch->ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/separate")
        ->assertOk();

    Event::assertDispatched(TablesRegrouped::class, fn (TablesRegrouped $e): bool => ! $e->joined
        && $e->tableUlids === [$segunda->ulid]);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/separate")
        ->assertOk();

    Event::assertDispatchedTimes(TablesRegrouped::class, 2);
});

// ---------------------------------------------------------------------------
// Liberar a mano
// ---------------------------------------------------------------------------

it('una mesa ocupada por error se libera, y una libre responde 409', function () {
    $mesa = ($this->crearMesa)('M1');

    app(TenantContext::class)->set($this->tenant->id);
    $mesa->update(['status' => TableStatus::Occupied]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/free")
        ->assertOk()
        ->assertJsonPath('data.status', 'free');

    // 409 y no 422: no hay nada que corregir en la petición — el estado del negocio ya es el pedido.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/free")
        ->assertStatus(409);
});

it('liberar a mano avisa al piso en vivo y deshace la unión que la mesa sostenía', function () {
    // Antes el controlador escribía el estado por su cuenta, fuera de `TableOccupancy`: las demás terminales seguían
    // viendo la mesa ocupada, y la mesa unida a ella quedaba colgando de un servicio que ya no existía.
    $principal = ($this->crearMesa)('M1');
    $unida = ($this->crearMesa)('M2');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/join", ['table_ulids' => [$unida->ulid]])
        ->assertOk();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $principal->update(['status' => TableStatus::Occupied]));

    Event::fake([TableStateChanged::class]);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$principal->ulid}/free")
        ->assertOk()
        ->assertJsonPath('data.status', 'free');

    Event::assertDispatched(TableStateChanged::class, fn (TableStateChanged $e): bool => $e->tableUlid === $principal->ulid
        && $e->from === TableStatus::Occupied->value
        && $e->to === TableStatus::Free->value);

    expect($unida->refresh()->joined_to_table_id)->toBeNull();
});

it('los nombres repetidos de plano y de zona se rechazan con 422, no con el 500 del índice', function () {
    $crear = fn (string $nombre, array $zonas) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/floor-plans', ['branch_ulid' => $this->branch->ulid, 'name' => $nombre, 'zones' => $zonas]);

    $plan = $crear('Planta baja', ['Salón'])->assertCreated()->json('data');

    // El mismo nombre en la misma sucursal (sin distinguir mayúsculas, como la columna).
    $crear('planta baja', ['Salón'])->assertUnprocessable()->assertJsonValidationErrors('name');

    // Dos zonas iguales en el mismo alta.
    $crear('Terraza', ['Afuera', 'afuera'])->assertUnprocessable();

    $otro = $crear('Terraza', ['Afuera'])->assertCreated()->json('data.ulid');

    // Renombrar un plano al nombre de otro de su sucursal.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/floor-plans/{$otro}", ['name' => 'Planta baja'])
        ->assertUnprocessable();

    // Una zona repetida dentro del mismo plano.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/floor-plans/{$plan['ulid']}/zones", ['name' => 'SALÓN'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('el filtro de mesas disponibles no ofrece las retiradas del piso', function () {
    ($this->crearMesa)('M1');
    $retirada = ($this->crearMesa)('M2');

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $retirada->archive());

    $codigos = array_column(
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->getJson('/api/v1/restaurant-tables?available_only=1')->assertOk()->json('data'),
        'code',
    );

    expect($codigos)->toBe(['M1']);

    // Y el recurso lo dice, para que el editor ofrezca «Devolver al piso».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/restaurant-tables')
        ->assertOk()
        ->assertJsonPath('data.1.is_archived', true);
});

it('las operaciones de piso respetan el alcance por sucursal', function () {
    // El tenant no protege de esto: es el mismo negocio. Quien sólo opera la sucursal Norte no libera, une ni separa
    // mesas de la matriz, ni edita las que no son suyas.
    $mesa = ($this->crearMesa)('M1');
    $otra = ($this->crearMesa)('M2');

    [$ajeno, $mesaNorte] = app(TenantContext::class)->runFor($this->tenant->id, function (): array {
        $norte = Branch::factory()->create(['code' => 'NTE', 'name' => 'Norte']);
        $plano = FloorPlan::create(['branch_id' => $norte->id, 'name' => 'Salón Norte', 'is_default' => true]);
        $zona = FloorZone::create(['floor_plan_id' => $plano->id, 'name' => 'Salón']);
        $mesaNorte = RestaurantTable::create(['branch_id' => $norte->id, 'floor_zone_id' => $zona->id, 'code' => 'N1', 'seats' => 4]);

        $persona = User::factory()->create();
        $membresia = TenantMembership::factory()->create([
            'user_id' => $persona->id, 'employee_code' => 'W009', 'has_all_branches' => false,
        ]);
        $membresia->branchScopes()->create(['branch_id' => $norte->id]);

        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->firstOrFail();
        $persona->syncRoles([$rol]);
        $membresia->update(['default_role_id' => $rol->id]);

        return [$persona, $mesaNorte];
    });

    // Control: en SU sucursal sí puede. Sin esto, un 403 por cualquier otro motivo se leería como que el alcance
    // funciona.
    $this->actingAsSpa($ajeno, $this->tenant->id)
        ->patchJson("/api/v1/restaurant-tables/{$mesaNorte->ulid}", ['name' => 'Ventana norte'])
        ->assertOk();

    $plan = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => FloorPlan::query()->where('branch_id', $this->branch->id)->firstOrFail(),
    );

    $peticiones = [
        ['post', "/api/v1/restaurant-tables/{$mesa->ulid}/join", ['table_ulids' => [$otra->ulid]]],
        ['post', "/api/v1/restaurant-tables/{$mesa->ulid}/separate", []],
        ['post', "/api/v1/restaurant-tables/{$mesa->ulid}/free", []],
        ['patch', "/api/v1/restaurant-tables/{$mesa->ulid}", ['name' => 'Ventana']],
        ['post', "/api/v1/floor-plans/{$plan->ulid}/default", []],
    ];

    foreach ($peticiones as [$metodo, $url, $cuerpo]) {
        $this->actingAsSpa($ajeno, $this->tenant->id)->json($metodo, $url, $cuerpo)->assertForbidden();
    }

    expect($otra->refresh()->joined_to_table_id)->toBeNull()
        ->and($mesa->refresh()->name)->not->toBe('Ventana');
});

// ---------------------------------------------------------------------------
// Aislamiento y autorización
// ---------------------------------------------------------------------------

it('las mesas de un negocio no se ven desde otro', function () {
    ($this->crearMesa)('M1');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/restaurant-tables')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('el mesero ve el salón y une mesas, pero no lo configura', function () {
    $mesa = ($this->crearMesa)('M1');
    $otra = ($this->crearMesa)('M2');

    app(TenantContext::class)->set($this->tenant->id);

    $mesero = User::factory()->create();

    $membresia = TenantMembership::factory()->create([
        'user_id' => $mesero->id,
        'employee_code' => 'W001',
        'has_all_branches' => true,
    ]);

    $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $mesero->syncRoles([$rol]);
    $membresia->update(['default_role_id' => $rol->id]);

    app(TenantContext::class)->forget();

    // Ve el salón: sin eso no sabe dónde sentar.
    $this->actingAsSpa($mesero, $this->tenant->id)
        ->getJson('/api/v1/restaurant-tables')
        ->assertOk();

    // Une mesas: es una operación de PISO, la hace cuando llegan ocho.
    $this->actingAsSpa($mesero, $this->tenant->id)
        ->postJson("/api/v1/restaurant-tables/{$mesa->ulid}/join", ['table_ulids' => [$otra->ulid]])
        ->assertOk();

    // Y no configura el salón: un mesero que pudiera borrar la mesa 4 a media noche sería un problema.
    $this->actingAsSpa($mesero, $this->tenant->id)
        ->postJson('/api/v1/restaurant-tables', [
            'floor_zone_ulid' => ($this->zonaPorOmision)()->ulid,
            'code' => 'M99',
        ])
        ->assertForbidden();
});

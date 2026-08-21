<?php

declare(strict_types=1);

use App\Modules\Floor\Application\TableOccupancy;
use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Events\Broadcast\FloorChanged;
use App\Modules\Shared\Domain\Events\TableStateChanged;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Listeners\BroadcastFloorChanges;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Event;

/**
 * EL PISO EN VIVO: EVENTOS Y CANALES (Iteración 5, pasos 9–12)
 *
 * ## Lo que estas pruebas fijan
 *
 * **Que el aviso sale de un solo sitio.** `TableOccupancy` es el único punto que escribe el estado de una mesa —lo es
 * desde el paso 7 de la Iteración 4, donde se centralizó— y por eso es el único que emite. Repartido por tres módulos,
 * el piso en vivo se perdería transiciones sin que nada fallara.
 *
 * **Que no se avisa de lo que no cambió.** Marcar «ocupada» una mesa ya ocupada no es un hecho: repetirlo llenaría el
 * canal de mensajes vacíos y haría parpadear el piso sin motivo.
 *
 * **Y que un canal se autoriza como un endpoint.** Es lo que más importa aquí: un canal privado se pide con el ULID de
 * sucursal que manda el CLIENTE. Es el hueco que D292 cerró en once endpoints, en una superficie nueva donde todavía
 * no hay costumbre — y el `tenant_id` no protege, porque la sucursal ajena es del mismo negocio.
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

    $this->ajena = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);

    $plan = FloorPlan::create([
        'branch_id' => $this->branch->id,
        'name' => 'Planta baja',
        'is_default' => true,
    ]);

    $zona = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón', 'sort_order' => 10]);

    $this->mesa = RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $zona->id,
        'code' => 'M1',
        'seats' => 4,
    ]);

    app(TenantContext::class)->forget();

    /** Alguien con alcance a UNA sola sucursal, que es sobre quien se puede probar el alcance. */
    $this->limitado = function (string $rol, string $codigo, string $correo): User {
        return app(TenantContext::class)->runFor($this->tenant->id, function () use ($rol, $codigo, $correo): User {
            $persona = User::factory()->create(['email' => $correo]);

            $membresia = TenantMembership::factory()->create([
                'user_id' => $persona->id,
                'employee_code' => $codigo,
                'has_all_branches' => false,
            ]);

            $membresia->branchScopes()->create(['branch_id' => $this->branch->id]);

            $papel = Role::query()->where('name', $rol)->firstOrFail();
            $persona->syncRoles([$papel]);
            $membresia->update(['default_role_id' => $papel->id]);

            return $persona;
        });
    };


    /**
     * Apunta la difusión a un broadcaster que SÍ consulta los canales.
     *
     * Se llama SÓLO en las pruebas de canal, no en el `beforeEach`: con el driver activo para todo el archivo,
     * cualquier difusión real —la que provoca ocupar una mesa de verdad— intenta salir a la red y falla con un error
     * de credenciales que no dice nada del guardián.
     */
    $this->conCanalesReales = function (): void {
        // ------------------------------------------------------------------------------------------------------
        // SIN ESTO, LAS PRUEBAS DE CANAL PASAN AUTORICE O NO.
        //
        // `phpunit.xml` fija `BROADCAST_CONNECTION=null`, y `NullBroadcaster::auth()` **no hace nada**: devuelve un 200
        // vacío sin llegar a consultar `routes/channels.php`. Una prueba que comprobara «este canal se rechaza» pasaría
        // en verde con el guardián invertido, con el guardián borrado, o sin guardián.
        //
        // Lo encontré escribiendo estas pruebas: las de denegación fallaban con 200 y el registro decía que el callback
        // del canal nunca se ejecutaba.
        //
        // Se apunta a un broadcaster que SÍ consulta los canales. Las credenciales son de mentira a propósito: firmar un
        // canal privado es un HMAC local, no una llamada de red, así que esto no habla con ningún servidor.
        // ------------------------------------------------------------------------------------------------------
        // Se usa `pusher` y no `reverb` porque firmar un canal privado con él es un HMAC LOCAL. El driver de Reverb
        // consulta al servidor para completar la respuesta, y sin servidor levantado la prueba fallaba con un error de
        // cURL — que no dice nada sobre el guardián, que es lo que aquí se está probando. Reverb habla el protocolo de
        // Pusher, así que la autorización que se ejercita es la misma.
        config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'llavedeprueba123',
        'broadcasting.connections.pusher.secret' => 'secretodeprueba123',
        'broadcasting.connections.pusher.app_id' => '123456',
        ]);

        // La conexión se PURGA para que se reconstruya con estas credenciales: el gestor cachea el cliente, y el que
        // había nació con las de producción —vacías en pruebas—, así que firmar respondía «auth_key should be a valid app
        // key» en la única prueba que llegaba a firmar.
        app(BroadcastManager::class)->purge('pusher');


        // Y RE-REGISTRAR LOS CANALES, que es la segunda mitad de la trampa.
        //
        // `Broadcast::channel()` registra sobre la conexión que es la de omisión **al arrancar** — la nula. Cambiar la
        // omisión aquí resuelve otra conexión, que nace sin ningún canal: `verifyUserCanAccessChannel` no encuentra patrón
        // que case y lanza 403 para TODO. Con eso, las pruebas de rechazo pasaban en verde por el motivo equivocado y las
        // de aprobación fallaban sin decir por qué.
        require base_path('routes/channels.php');

    };

    /** Pide autorización para un canal, como hace el cliente de WebSockets. */
    $this->autorizar = fn (User $user, string $canal) => $this->actingAsSpa($user, $this->tenant->id)
        ->postJson('/api/v1/broadcasting/auth', ['channel_name' => $canal, 'socket_id' => '1234.5678']);
});

afterEach(fn () => app(TenantContext::class)->forget());

// ------------------------------------------------------------------ Eventos

it('ocupar una mesa emite el cambio de estado, con el estado ANTERIOR', function () {
    Event::fake([TableStateChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);
    app(TableOccupancy::class)->occupy($this->mesa);
    app(TenantContext::class)->forget();

    // El anterior no es adorno: sin él, una pantalla que recibe «ocupada» no distingue «acaban de sentarse» de
    // «seguía ocupada y algo más cambió», que son dos avisos distintos para quien coordina el salón.
    Event::assertDispatched(
        TableStateChanged::class,
        fn (TableStateChanged $e): bool => $e->tableUlid === $this->mesa->ulid
            && $e->from === TableStatus::Free->value
            && $e->to === TableStatus::Occupied->value
            && $e->branchUlid === $this->branch->ulid,
    );
});

it('NO se avisa de lo que no cambió', function () {
    app(TenantContext::class)->set($this->tenant->id);
    app(TableOccupancy::class)->occupy($this->mesa);

    Event::fake([TableStateChanged::class]);

    // Pedir la cuenta dos veces: el segundo no es un hecho nuevo.
    app(TableOccupancy::class)->markBillRequested($this->mesa->refresh());
    app(TableOccupancy::class)->markBillRequested($this->mesa->refresh());

    app(TenantContext::class)->forget();

    Event::assertDispatchedTimes(TableStateChanged::class, 1);
});

it('el oyente traduce el hecho de dominio en un aviso para el canal', function () {
    Event::fake([FloorChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    app(BroadcastFloorChanges::class)->handle(new TableStateChanged(
        tenantId: (int) $this->tenant->id,
        tableUlid: (string) $this->mesa->ulid,
        branchUlid: (string) $this->branch->ulid,
        from: TableStatus::Free->value,
        to: TableStatus::Occupied->value,
        accountUlid: null,
    ));

    app(TenantContext::class)->forget();

    Event::assertDispatched(FloorChanged::class, function (FloorChanged $e): bool {
        // El ULID del negocio, no la llave interna: un canal SÍ sale al cliente, y el id secuencial diría cuántos
        // negocios hay y en qué orden se dieron de alta.
        expect($e->tenantUlid)->toBe((string) $this->tenant->ulid);
        expect($e->broadcastOn()[0]->name)
            ->toBe("private-tenant.{$this->tenant->ulid}.branch.{$this->branch->ulid}.floor");

        // Y lo que viaja es el mínimo: ni totales, ni nombres de cliente.
        expect(array_keys($e->broadcastWith()))
            ->toBe(['table_ulid', 'table_status', 'account_ulid', 'reason']);

        return true;
    });
});

// ------------------------------------------------------------------ Canales

it('el canal del piso se autoriza para la sucursal propia', function () {
    ($this->conCanalesReales)();

    $mesero = ($this->limitado)(RoleTemplates::WAITER, 'M001', 'mesero@fonda.mx');

    ($this->autorizar)($mesero, "private-tenant.{$this->tenant->ulid}.branch.{$this->branch->ulid}.floor")
        ->assertOk();
});

it('el canal de una sucursal FUERA DE ALCANCE se rechaza', function () {
    ($this->conCanalesReales)();

    // El mismo negocio, otra sucursal: pasa el global scope y llega como un modelo válido. Quien tiene que decir que
    // no es `membership_branch_scopes`.
    $mesero = ($this->limitado)(RoleTemplates::WAITER, 'M001', 'mesero@fonda.mx');

    ($this->autorizar)($mesero, "private-tenant.{$this->tenant->ulid}.branch.{$this->ajena->ulid}.floor")
        ->assertForbidden();
});

it('el canal de OTRO negocio se rechaza aunque la sucursal exista', function () {
    ($this->conCanalesReales)();

    app(TenantContext::class)->forget();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    // El canal nombra el negocio ajeno y su sucursal. Sin la primera comprobación, el segundo tramo del nombre
    // mandaría sobre el primero.
    ($this->autorizar)($this->owner, "private-tenant.{$otro['tenant']->ulid}.branch.{$otro['branch']->ulid}.floor")
        ->assertForbidden();
});

it('el canal de un área exige que el área sea DE ESA sucursal', function () {
    ($this->conCanalesReales)();

    app(TenantContext::class)->set($this->tenant->id);

    // Cada área consume de un almacén: es la topología de D11, y la columna es NOT NULL.
    $almacen = Warehouse::create([
        'branch_id' => $this->branch->id,
        'kind' => WarehouseKind::Branch,
        'code' => 'ALM',
        'name' => 'Almacén',
    ]);

    $propia = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COC',
        'name' => 'Cocina',
    ]);

    $deLaAjena = PreparationArea::create([
        'branch_id' => $this->ajena->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COC2',
        'name' => 'Cocina',
    ]);

    app(TenantContext::class)->forget();

    $base = "private-tenant.{$this->tenant->ulid}.branch.{$this->branch->ulid}.area";

    ($this->autorizar)($this->owner, "{$base}.{$propia->ulid}")->assertOk();

    // La cocina de Polanco no recibe lo de Roma Norte. Las dos son del mismo negocio, así que nada más lo impediría.
    ($this->autorizar)($this->owner, "{$base}.{$deLaAjena->ulid}")->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * IMPRESORAS Y RUTEO DE IMPRESIÓN (§9.1 de la Iteración 4)
 *
 * ## Qué resuelve la tabla
 *
 * Las áreas de preparación y las terminales existían desde la Iteración 1 y **no tenían a dónde imprimir**. Sin esto,
 * «ruteo por área» no tiene destino y el cajón de dinero —que se abre mandando una secuencia a la impresora de
 * tickets— no se puede abrir.
 *
 * ## El montaje usa `ProvisionTenant`
 *
 * El mismo servicio del alta real, como en el CRUD de sucursales: así estas pruebas ejercitan también el camino por el
 * que nacen los negocios, y un alta que dejara al propietario sin los permisos nuevos se vería aquí.
 */
beforeEach(function () {
    $altaA = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenantA = $altaA['tenant'];
    $this->ownerA = $altaA['owner'];
    $this->branchA = $altaA['branch'];

    $altaB = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenantB = $altaB['tenant'];
    $this->ownerB = $altaB['owner'];
    $this->branchB = $altaB['branch'];

    // El alta de un negocio NO crea áreas de preparación: son una decisión del negocio (D11, la topología de
    // almacenes es flexible), así que la prueba crea la suya. Lo descubrí aquí, con un `firstOrFail()` que no
    // encontraba nada — y conviene decirlo porque es fácil suponer que un alta deja el salón montado.
    app(TenantContext::class)->set($this->tenantA->id);

    $this->areaA = PreparationArea::create([
        'branch_id' => $this->branchA->id,
        'warehouse_id' => $this->branchA->default_warehouse_id,
        'code' => 'COCINA',
        'name' => 'Cocina',
    ]);

    app(TenantContext::class)->forget();

    /** Cuerpo de alta, con lo mínimo y lo justo. */
    $this->cuerpo = fn (array $extra = []): array => array_merge([
        'branch_ulid' => $this->branchA->ulid,
        'code' => 'COCINA',
        'name' => 'Impresora de cocina',
        'connection' => 'network',
        'target' => '192.168.1.50:9100',
    ], $extra);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Alta
// ---------------------------------------------------------------------------

it('da de alta una impresora con sus valores por omisión', function () {
    $respuesta = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->assertJsonPath('data.code', 'COCINA')
        ->assertJsonPath('data.connection_label', 'Red (IP)')
        // Los valores por omisión llegan EN LA RESPUESTA, no sólo a la base. Es el candado D217 de la Iteración 3:
        // Eloquent devuelve lo asignado y no lo almacenado, así que sin `refresh()` estos dos vendrían nulos.
        ->assertJsonPath('data.paper_width', 80)
        ->assertJsonPath('data.supports_cash_drawer', false)
        ->assertJsonPath('data.can_open_cash_drawer', false)
        ->assertJsonPath('data.assignments.preparation_areas', 0);

    expect($respuesta->json('data.ulid'))->toBeString()->toHaveLength(26);
});

it('el código se guarda en mayúsculas y no se repite en la misma sucursal', function () {
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)(['code' => 'barra']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'BARRA');

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)(['code' => 'BARRA', 'name' => 'Otra']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

it('rechaza un ancho de papel que no existe', function () {
    // 58 y 80 son los dos rollos térmicos del mercado. Aceptar cualquier número dejaría entrar un dedazo que después
    // rompe el formato del ticket sin que nadie entienda por qué.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)(['paper_width' => 63]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['paper_width']]);
});

it('el catálogo de conexiones viene del servidor con su pista de destino', function () {
    $conexiones = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/printers/connections')
        ->assertOk()
        ->json('data');

    expect(array_column($conexiones, 'value'))->toBe(['network', 'usb', 'windows_share']);

    // La pista existe para que nadie capture una IP donde va una ruta compartida. Va por API y no escrita en el
    // cliente por la lección de D139: una etiqueta duplicada en el frontend acaba diciendo algo distinto de lo que el
    // servidor valida.
    foreach ($conexiones as $conexion) {
        expect($conexion['target_hint'])->toBeString()->not->toBeEmpty();
    }
});

// ---------------------------------------------------------------------------
// El cajón de dinero
// ---------------------------------------------------------------------------

it('sólo puede abrir el cajón una impresora con conector Y activa', function () {
    $respuesta = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)(['code' => 'CAJA', 'supports_cash_drawer' => true]))
        ->assertCreated()
        ->assertJsonPath('data.can_open_cash_drawer', true);

    $ulid = $respuesta->json('data.ulid');

    // Al darla de baja pierde la capacidad aunque conserve el conector: la conjunción se resuelve en el servidor para
    // que la interfaz no lleve su propia copia de la regla.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson("/api/v1/printers/{$ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.supports_cash_drawer', true)
        ->assertJsonPath('data.can_open_cash_drawer', false);
});

// ---------------------------------------------------------------------------
// Edición
// ---------------------------------------------------------------------------

it('una impresora sí cambia de destino y de conexión', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    // A diferencia de una terminal, una impresora cambia de sitio de verdad: se quema y se sustituye por otra con
    // distinta IP, o se pasa de red a USB. Prohibirlo obligaría a darla de baja y reasignar todas las áreas.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/printers/{$ulid}", [
            'connection' => 'usb',
            'target' => 'POS-58',
            'paper_width' => 58,
        ])
        ->assertOk()
        ->assertJsonPath('data.connection', 'usb')
        ->assertJsonPath('data.target', 'POS-58')
        ->assertJsonPath('data.paper_width', 58);
});

it('no cambia de sucursal ni de código', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/printers/{$ulid}", ['code' => 'OTRO'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/printers/{$ulid}", ['branch_ulid' => $this->branchA->ulid])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['branch_ulid']]);
});

// ---------------------------------------------------------------------------
// El ruteo: quién imprime qué
// ---------------------------------------------------------------------------

it('un área imprime sus comandas y una terminal sus tickets', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    app(TenantContext::class)->set($this->tenantA->id);
    $terminal = Terminal::create(['branch_id' => $this->branchA->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    app(TenantContext::class)->forget();

    $area = $this->areaA;

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['printer_ulid' => $ulid])
        ->assertOk()
        ->assertJsonPath('data.printer.code', 'COCINA');

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/terminals/{$terminal->ulid}", ['printer_ulid' => $ulid])
        ->assertOk()
        ->assertJsonPath('data.printer.code', 'COCINA');

    // El listado dice cuántos destinos dependen de ella. Es lo que alguien necesita saber ANTES de darla de baja:
    // «esta imprime las comandas de un área y los tickets de una caja» cambia la decisión.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson("/api/v1/printers/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.assignments.preparation_areas', 1)
        ->assertJsonPath('data.assignments.terminals', 1);
});

it('mandar null desasigna, y omitir el campo no toca nada', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    $area = $this->areaA;

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['printer_ulid' => $ulid])
        ->assertOk();

    // Editar el nombre SIN mandar `printer_ulid` no debe borrar la impresora. Es la diferencia entre «no vino» y
    // «vino nulo», y se distingue con `has()` y no con el valor.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['name' => 'Cocina caliente'])
        ->assertOk()
        ->assertJsonPath('data.printer.code', 'COCINA');

    // `null` explícito sí desasigna: un área puede dejar de imprimir sin dejar de existir.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['printer_ulid' => null])
        ->assertOk()
        ->assertJsonPath('data.printer', null);
});

it('dar de baja una impresora NO desasigna las áreas que la citaban', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    $area = $this->areaA;

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['printer_ulid' => $ulid])
        ->assertOk();

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson("/api/v1/printers/{$ulid}/archive")
        ->assertOk();

    // Parece descuido y es lo contrario: si la baja limpiara las asignaciones, la información de «esta área imprimía
    // aquí» desaparecería justo cuando hace falta para reconfigurar, y quien sustituya la impresora no sabría qué
    // áreas reasignar.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson("/api/v1/preparation-areas/{$area->ulid}")
        ->assertOk()
        ->assertJsonPath('data.printer.code', 'COCINA');
});

// ---------------------------------------------------------------------------
// Aislamiento y autorización
// ---------------------------------------------------------------------------

it('no se ve ni se alcanza la impresora de otro negocio', function () {
    // La impresora ajena se crea POR MODELO, dentro del contexto del otro negocio, y no por API.
    //
    // Dos razones. La buena: lo que esta prueba verifica es el aislamiento de lectura, y el estado de partida no
    // necesita pasar por HTTP para ser real. Y la práctica: alternar de usuario autenticado dentro de una misma prueba
    // hace que la segunda sesión llegue sin autenticar —recibí 401 en la petición del primer negocio después de haber
    // actuado como el segundo—, así que el helper de sesión no admite ir y venir. Conviene saberlo antes de escribir
    // otra prueba de aislamiento.
    $ajena = app(TenantContext::class)->runFor($this->tenantB->id, fn () => Printer::create([
        'branch_id' => $this->branchB->id,
        'code' => 'AJENA',
        'name' => 'Impresora del vecino',
        'connection' => 'network',
        'target' => '10.0.0.9:9100',
    ])->ulid);

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/printers')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Por ULID directo tampoco: el global scope hace que el enlace implícito no la encuentre.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson("/api/v1/printers/{$ajena}")
        ->assertNotFound();

    // Y no se puede asignar a un área propia una impresora ajena, que es el camino por el que se colaría una fuga:
    // la regla de validación acota por `tenant_id`.
    $area = $this->areaA;

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/preparation-areas/{$area->ulid}", ['printer_ulid' => $ajena])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['printer_ulid']]);
});

it('quien no administra impresoras no las crea', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    // Se crea una persona aparte en lugar de cambiarle el rol al propietario, y hay dos razones. La membresía es única
    // por (negocio, usuario), así que el propietario ya tiene la suya con alcance total; y sobre todo: el rol ACTIVO
    // sale del `default_role_id` de la membresía, no de los roles asignados (D9). Cambiar `syncRoles` sin tocar la
    // membresía deja al propietario operando como propietario, y la prueba pasaría por la razón equivocada — de hecho
    // así fue en mi primera versión, que recibió 201 donde esperaba 403.
    $persona = User::factory()->create();

    $membresia = TenantMembership::factory()->create([
        'user_id' => $persona->id,
        'employee_code' => 'A001',
        'has_all_branches' => true,
    ]);

    // El almacenista es la plantilla que NO recibe permisos de organización: recibe mercancía, no configura hardware.
    $rol = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();

    $persona->syncRoles([$rol]);
    $membresia->update(['default_role_id' => $rol->id]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($persona, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Auditoría
// ---------------------------------------------------------------------------

it('el alta y la baja quedan en la bitácora', function () {
    $ulid = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/printers', ($this->cuerpo)())
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson("/api/v1/printers/{$ulid}/archive")
        ->assertOk();

    app(TenantContext::class)->set($this->tenantA->id);

    $acciones = AuditEntry::query()
        ->whereIn('action', [AuditAction::PRINTER_CREATED, AuditAction::PRINTER_UPDATED])
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($acciones)->toBe([AuditAction::PRINTER_CREATED, AuditAction::PRINTER_UPDATED]);
});

// ---------------------------------------------------------------------------
// Garantía estructural
// ---------------------------------------------------------------------------

it('la base impide dos impresoras con el mismo código en la sucursal', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    Printer::create([
        'branch_id' => $this->branchA->id,
        'code' => 'COCINA',
        'name' => 'Una',
        'connection' => 'network',
        'target' => '1.1.1.1:9100',
    ]);

    // Se prueba SIN pasar por la aplicación, que es la lección de D218: un `unique` puede estar en el diseño y no en la
    // base, y probarlo por la API sólo probaría la validación del Form Request.
    expect(fn () => Printer::create([
        'branch_id' => $this->branchA->id,
        'code' => 'COCINA',
        'name' => 'Otra',
        'connection' => 'usb',
        'target' => 'POS-80',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

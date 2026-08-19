<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PROVEEDORES (D26)
 *
 * La primera tabla del módulo `Purchasing`. Lo que estas pruebas cuidan es la **identidad** del proveedor: que no se
 * pueda capturar dos veces la misma persona moral, que su código no cambie nunca, y que se dé de baja en lugar de
 * borrarse.
 *
 * Las tres tienen el mismo síntoma cuando fallan: las compras se reparten entre dos fichas y «¿cuánto le compro a
 * éste?» da la mitad.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que compra',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Lucía',
        ownerPaternalSurname: 'Estrada',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Cuerpo mínimo de un proveedor. */
function proveedor(array $extra = []): array
{
    return array_merge([
        'code' => 'DON-BETO',
        'legal_name' => 'Distribuidora de Alimentos del Bajío S.A. de C.V.',
        'trade_name' => 'Don Beto',
    ], $extra);
}

// ------------------------------------------------------------------------ Alta

it('da de alta un proveedor con su razón social y su nombre comercial', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor([
            'rfc' => 'DAB120315ABC',
            'contact_name' => 'Beto Ramírez',
            'phone' => '4771234567',
            'email' => 'ventas@donbeto.mx',
            'payment_terms_days' => 30,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.legal_name', 'Distribuidora de Alimentos del Bajío S.A. de C.V.')
        ->assertJsonPath('data.trade_name', 'Don Beto')
        // El nombre que la gente usa lo calcula el servidor: «el comercial si lo tiene, la razón social si no» es una
        // regla, y duplicada en cada pantalla se aplica distinto en alguna (la lección de D139).
        ->assertJsonPath('data.display_name', 'Don Beto')
        ->assertJsonPath('data.payment_terms_days', 30)
        ->assertJsonPath('data.is_active', true);
});

it('sin nombre comercial, el nombre que se usa es la razón social', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', ['code' => 'MERCADO', 'legal_name' => 'Puesto 14 del mercado'])
        ->assertCreated();

    expect($respuesta->json('data.display_name'))->toBe('Puesto 14 del mercado')
        ->and($respuesta->json('data.trade_name'))->toBeNull()
        // `null` es «no se sabe» y cero sería «de contado»: son cosas distintas, así que el nulo no se rellena.
        ->and($respuesta->json('data.payment_terms_days'))->toBeNull();
});

it('el código se normaliza a mayúsculas', function () {
    // La columna es `ascii_bin` (D58), así que sin normalizar `don-beto` y `DON-BETO` serían dos proveedores distintos
    // y el índice único no los atraparía.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['code' => 'don-beto']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'DON-BETO');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['code' => 'DON-BETO', 'legal_name' => 'Otro']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

// -------------------------------------------------------------------- El RFC

it('NO admite dos proveedores con el mismo RFC', function () {
    // Son la misma persona moral capturada dos veces, y el síntoma es que las compras se reparten entre dos fichas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['rfc' => 'DAB120315ABC']))
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor([
            'code' => 'OTRO',
            'legal_name' => 'Distribuidora del Bajío',
            'rfc' => 'DAB120315ABC',
        ]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['rfc']]);
});

it('admite MUCHOS proveedores sin RFC', function () {
    // Ésta es la prueba que justifica el índice único normal en lugar del truco de la columna generada de D93: allá el
    // problema era que MySQL NO deduplica `NULL`; aquí ese comportamiento es exactamente el que se quiere. El puesto
    // del mercado no tiene RFC, y varios puestos no se pueden estorbar.
    foreach (['MERCADO-1', 'MERCADO-2', 'MERCADO-3'] as $codigo) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/suppliers', ['code' => $codigo, 'legal_name' => "Puesto {$codigo}"])
            ->assertCreated();
    }

    // Y la cadena vacía se normaliza a nulo: sin eso, el segundo proveedor «sin RFC» sería rechazado por duplicado —
    // un error incomprensible para quien sólo dejó el campo en blanco.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', ['code' => 'MERCADO-4', 'legal_name' => 'Puesto 4', 'rfc' => ''])
        ->assertCreated()
        ->assertJsonPath('data.rfc', null);
});

it('el RFC se normaliza a mayúsculas y valida su forma', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['rfc' => 'dab120315abc']))
        ->assertCreated()
        ->assertJsonPath('data.rfc', 'DAB120315ABC');

    // Forma, no validez fiscal: validar el dígito verificador exigiría el algoritmo del SAT, y un RFC bien formado
    // pero inexistente se descubre al facturar. Lo que esto evita es el dedazo evidente.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['code' => 'OTRO', 'rfc' => '12345']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['rfc']]);
});

// ------------------------------------------------------------------- Edición

it('el CÓDIGO no se puede cambiar', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor())
        ->assertCreated()
        ->json('data.ulid');

    // Es el identificador con el que la gente lo llama en papeles y conversaciones: reasignarlo haría que los
    // documentos viejos parecieran ser de otro proveedor. Es la misma razón que el código de un lote (D23).
    //
    // El Form Request no lo admite, así que se ignora en silencio en lugar de reventar — y el modelo lo bloquearía
    // igual si alguien lo intentara por otro camino.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/suppliers/{$ulid}", ['code' => 'OTRO-CODIGO'])
        ->assertOk()
        ->assertJsonPath('data.code', 'DON-BETO');

    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => Supplier::query()->where('ulid', $ulid)->sole()->update(['code' => 'FORZADO']))
        ->toThrow(RuntimeException::class);
});

it('lo demás sí se corrige, incluido el RFC', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['rfc' => 'DAB120315ABC']))
        ->assertCreated()
        ->json('data.ulid');

    // El RFC se teclea mal, y corregirlo no reinterpreta ninguna compra pasada: lo que la compra cita es el proveedor,
    // no su RFC.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/suppliers/{$ulid}", [
            'rfc' => 'DAB120315XYZ',
            'phone' => '4779998877',
            'legal_name' => 'Distribuidora de Alimentos del Bajío S. de R.L.',
        ])
        ->assertOk()
        ->assertJsonPath('data.rfc', 'DAB120315XYZ')
        ->assertJsonPath('data.phone', '4779998877');
});

it('se da de BAJA, y no hay endpoint para borrarlo', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor())
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/suppliers/{$ulid}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // Sus recepciones y su historial de precios lo citan: borrarlo dejaría compras sin poder decir a quién se le
    // compraron. Por eso la ruta no existe.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/suppliers/{$ulid}")
        ->assertStatus(405);
});

// ------------------------------------------------------------------- Listado

it('lista y busca proveedores por nombre, código y RFC', function () {
    foreach ([
        ['code' => 'DON-BETO', 'legal_name' => 'Distribuidora del Bajío', 'trade_name' => 'Don Beto', 'rfc' => 'DAB120315ABC'],
        ['code' => 'LACTEOS', 'legal_name' => 'Lácteos Rodríguez S.A.', 'trade_name' => 'Lácteos Rodríguez'],
        ['code' => 'MERCADO', 'legal_name' => 'Puesto 14'],
    ] as $datos) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/suppliers', $datos)
            ->assertCreated();
    }

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        // Ordenados por razón social.
        ->assertJsonPath('data.0.code', 'DON-BETO');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers?search=Beto')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Por CÓDIGO, que es lo que viene escrito en los papeles.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers?search=LACTEOS')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Con ACENTOS, sobre una tabla que tiene columnas `ascii_bin`. Éste es el caso que reventaba con 500 en siete
    // listados desde la Iteración 1 (D137): `ListQuery` descarta las columnas ASCII cuando el término no lo es, y no
    // pierde resultados porque una columna ASCII no puede contener «Rodríguez».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers?search='.urlencode('Rodríguez'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers?status=inactive')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// --------------------------------------------------------------- Autorización

it('el almacenista VE proveedores y no los administra', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor())
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $this->owner->syncRoles([$almacenista]);
    app(TenantContext::class)->forget();

    // Ve: recibe la mercancía y necesita saber de quién es (D161).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->getJson('/api/v1/suppliers')
        ->assertOk();

    // No administra: dar de alta un proveedor es una decisión comercial.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson('/api/v1/suppliers', proveedor(['code' => 'NUEVO']))
        ->assertForbidden();
});

it('el mesero no ve proveedores', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/suppliers')
        ->assertForbidden();
});

// ----------------------------------------------------------- Aislamiento

it('un negocio no ve los proveedores de otro, y los códigos no se estorban', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Otra fonda',
        ownerEmail: 'otra@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-1',
    );

    // El proveedor del otro negocio se crea SIN pasar por HTTP, con el mismo código y el mismo RFC. Que la escritura
    // no falle es la prueba de que la unicidad es por negocio y no global: dos restaurantes le compran al mismo Don
    // Beto y cada uno lo captura en su catálogo.
    //
    // Y se hace así, y no autenticando al otro dueño, porque dos `actingAsSpa` con usuarios distintos en la misma
    // prueba producen un 401 — la sesión del primero sigue puesta. Es el patrón que usan las demás pruebas de
    // aislamiento del proyecto.
    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        Supplier::create(proveedor(['rfc' => 'DAB120315ABC']));
    });

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/suppliers', proveedor(['rfc' => 'DAB120315ABC']))
        ->assertCreated();

    // Y el listado del primero ve UNO: el suyo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/suppliers')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

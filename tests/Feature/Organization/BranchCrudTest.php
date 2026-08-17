<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * CRUD de sucursales por API.
 *
 * El montaje usa `ProvisionTenant`, el mismo servicio del alta real, en lugar de armar el
 * escenario a mano: así estas pruebas ejercitan también el camino por el que nacen los
 * negocios de verdad, y un alta que dejara al propietario sin permisos se vería aquí.
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
    $this->branchB = $altaB['branch'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Lectura y aislamiento
// ---------------------------------------------------------------------------

it('lista sólo las sucursales del tenant propio', function () {
    $respuesta = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches');

    $respuesta->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $this->branchA->ulid);
});

it('la sucursal de otro tenant devuelve 404, no 403', function () {
    // 404 y no 403 a propósito: un 403 confirmaría que el recurso existe. No se revela la
    // existencia de nada de otro negocio (ADR-002).
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson("/api/v1/branches/{$this->branchB->ulid}")
        ->assertNotFound()
        ->assertJsonPath('type', 'not_found');
});

it('no expone la llave primaria interna', function () {
    $datos = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson("/api/v1/branches/{$this->branchA->ulid}")
        ->json('data');

    expect($datos)->not->toHaveKey('id');
    expect($datos)->not->toHaveKey('tenant_id');
    expect($datos['ulid'])->toHaveLength(26);
});

// ---------------------------------------------------------------------------
// Whitelist de filtros (§8)
// ---------------------------------------------------------------------------

it('acepta los filtros declarados', function () {
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => Branch::factory()->inactive()->create(['code' => 'OLD', 'name' => 'Antigua'])
    );

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'OLD');
});

it('RECHAZA un filtro no declarado en lugar de ignorarlo', function () {
    // Ignorarlo devolvería la lista completa a alguien que cree estar viendo una filtrada: el
    // peor resultado, porque parece correcto.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?tenant_id=99')
        ->assertStatus(422);
});

it('rechaza ordenar por una columna no declarada', function () {
    // Ordenar por una columna sin índice degrada la base; ordenar por una que no debería
    // revelarse filtra información por diferencia de resultados.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?sort=timezone')
        ->assertStatus(422)
        ->assertJsonPath('type', 'validation_error');
});

it('ordena por las columnas declaradas, ascendente y descendente', function () {
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => Branch::factory()->create(['code' => 'AAA', 'name' => 'Aurora'])
    );

    $ascendente = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?sort=code')->json('data.0.code');

    $descendente = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?sort=-code')->json('data.0.code');

    expect($ascendente)->toBe('AAA');
    expect($descendente)->toBe('MTZ');
});

it('busca con colación acento-insensible', function () {
    app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn () => Branch::factory()->create(['code' => 'CAF', 'name' => 'Sucursal Café'])
    );

    // Sin la colación de D58 esto no encontraría nada: es la razón por la que se eligió.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?search=cafe')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'CAF');
});

it('acota el tamaño de página en lugar de servir miles de registros', function () {
    $meta = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches?per_page=5000')
        ->json('meta');

    expect($meta['per_page'])->toBe(100);
});

// ---------------------------------------------------------------------------
// Escritura
// ---------------------------------------------------------------------------

it('crea una sucursal y la audita', function () {
    $respuesta = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/branches', [
            'code' => 'sur',
            'name' => 'Sucursal Sur',
            'timezone' => 'America/Cancun',
        ]);

    $respuesta->assertCreated()
        // Normalizado a mayúsculas: entra en el folio y `cen` no debe ser otra sucursal.
        ->assertJsonPath('data.code', 'SUR')
        ->assertJsonPath('data.timezone', 'America/Cancun')
        ->assertJsonPath('data.status', 'active');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(AuditEntry::query()->where('action', AuditAction::BRANCH_CREATED)->exists())->toBeTrue();
});

it('rechaza un código repetido en el mismo tenant', function () {
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/branches', ['code' => 'MTZ', 'name' => 'Otra', 'timezone' => 'America/Mexico_City'])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'Ya existe una sucursal con ese código.');
});

it('permite el mismo código en otro tenant', function () {
    // El único es (tenant, código): dos negocios distintos pueden tener su sucursal MTZ.
    app(TenantContext::class)->set($this->tenantA->id);

    expect(Branch::query()->where('code', 'MTZ')->count())->toBe(1);

    app(TenantContext::class)->runFor($this->tenantB->id, function (): void {
        expect(Branch::query()->where('code', 'MTZ')->count())->toBe(1);
    });
});

it('rechaza una zona horaria inválida', function () {
    // Una zona inválida rompería el cálculo de "el día" en los cortes, y el error aparecería
    // semanas después en un reporte que no cuadra.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/branches', ['code' => 'X1', 'name' => 'X', 'timezone' => 'Marte/Olympus'])
        ->assertStatus(422)
        ->assertJsonPath('errors.timezone.0', 'La zona horaria no es válida. Ejemplo: America/Mexico_City.');
});

it('actualiza y registra el antes y el después', function () {
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/branches/{$this->branchA->ulid}", ['name' => 'Matriz Centro'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Matriz Centro');

    app(TenantContext::class)->set($this->tenantA->id);

    $entrada = AuditEntry::query()->where('action', AuditAction::BRANCH_UPDATED)->firstOrFail();

    expect($entrada->before['name'])->toBe('Matriz');
    expect($entrada->after['name'])->toBe('Matriz Centro');
});

it('PROHÍBE cambiar el código, con explicación', function () {
    // El código entra en los folios ya emitidos: cambiarlo dejaría documentos con una serie que
    // ya no corresponde a ninguna sucursal.
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/branches/{$this->branchA->ulid}", ['code' => 'NUEVO'])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', 'El código de la sucursal no se puede cambiar: entra en los folios ya emitidos. Da de baja la sucursal y crea otra.');
});

it('da de baja cambiando el estado, sin borrar', function () {
    // Hay ventas, cortes y folios apuntando aquí (D80).
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson("/api/v1/branches/{$this->branchA->ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    app(TenantContext::class)->set($this->tenantA->id);

    expect(Branch::query()->find($this->branchA->id))->not->toBeNull();
});

it('no se puede actualizar una sucursal de otro tenant', function () {
    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->patchJson("/api/v1/branches/{$this->branchB->ulid}", ['name' => 'Secuestrada'])
        ->assertNotFound();

    app(TenantContext::class)->runFor($this->tenantB->id, function (): void {
        expect($this->branchB->fresh()->name)->toBe('Matriz');
    });
});

// ---------------------------------------------------------------------------
// Autorización
// ---------------------------------------------------------------------------

it('un mesero no puede ni ver ni crear sucursales', function () {
    app(TenantContext::class)->set($this->tenantA->id);

    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->ownerA->assignRole($mesero);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/branches')
        ->assertForbidden()
        ->assertJsonPath('type', 'forbidden');

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson('/api/v1/branches', ['code' => 'X2', 'name' => 'X', 'timezone' => 'America/Mexico_City'])
        ->assertForbidden();
});

it('el 403 no revela qué permiso faltaba', function () {
    app(TenantContext::class)->set($this->tenantA->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->ownerA->assignRole($mesero);
    app(TenantContext::class)->forget();

    $cuerpo = $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/branches')
        ->json();

    expect(json_encode($cuerpo))->not->toContain('organization.branches');
});

it('un tenant en sólo lectura consulta pero no escribe', function () {
    // Impago con periodo de gracia: los datos son del tenant y tiene que poder consultarlos y
    // exportarlos; lo que no puede es operar.
    Tenant::query()->whereKey($this->tenantA->id)->update(['status' => 'read_only']);

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->getJson('/api/v1/branches')
        ->assertOk();

    $this->actingAsSpa($this->ownerA, $this->tenantA->id)
        ->postJson('/api/v1/branches', ['code' => 'X3', 'name' => 'X', 'timezone' => 'America/Mexico_City'])
        ->assertForbidden();
});

it('sin autenticar no se llega a ninguna parte', function () {
    $this->getJson('/api/v1/branches')
        ->assertUnauthorized()
        ->assertJsonPath('type', 'unauthenticated');
});

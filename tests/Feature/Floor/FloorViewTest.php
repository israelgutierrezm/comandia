<?php

declare(strict_types=1);

use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL PISO DE VENTA Y EL PLANO COMPLETO (Iteración 5, pasos 1)
 *
 * ## Dos endpoints, dos módulos, y no es burocracia
 *
 * `GET /floor-plans/{plan}` sirve al **editor** y vive en `Floor`: dibujar el salón no necesita saber quién está
 * sentado. `GET /branches/{branch}/floor` sirve al **piso de venta** y vive en `Pos`, porque junta la geometría con la
 * cuenta que ocupa cada mesa — y la dirección permitida es `Pos → Floor`. Al revés, `Floor` tendría que conocer las
 * cuentas y se cerraría un ciclo en el punto más caliente del sistema.
 *
 * ## Lo que estas pruebas fijan
 *
 * Que el piso se pinta con **una** petición, que **no trae dinero** —el permiso de esta pantalla lo tiene todo el que
 * atiende, y el de ver importes es otro—, y que una mesa retirada con cuenta encima **sigue viéndose**: si
 * desapareciera, la cuenta quedaría invisible en el piso y nadie la buscaría en un listado.
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

    $this->mesa = fn (string $code, int $seats = 4): RestaurantTable => RestaurantTable::create([
        'branch_id' => $this->branch->id,
        'floor_zone_id' => $this->zona->id,
        'code' => $code,
        'seats' => $seats,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el piso se pinta con una sola petición', function () {
    app(TenantContext::class)->set($this->tenant->id);
    ($this->mesa)('M1');
    ($this->mesa)('M2', 2);
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/branches/{$this->branch->ulid}/floor")
        ->assertOk();

    $respuesta->assertJsonPath('data.branch.ulid', $this->branch->ulid);
    $respuesta->assertJsonPath('data.plan.name', 'Planta baja');

    // El lienzo en centímetros, que es el `viewBox` del SVG. Sin él cada cliente supondría un tamaño.
    $respuesta->assertJsonPath('data.plan.canvas.unit', 'cm');
    $respuesta->assertJsonPath('data.plan.canvas.width', '1200.00');
    $respuesta->assertJsonPath('data.plan.canvas.height', '800.00');

    $respuesta->assertJsonCount(1, 'data.plan.zones');
    $respuesta->assertJsonCount(2, 'data.tables');
    $respuesta->assertJsonPath('data.tables.0.code', 'M1');
    $respuesta->assertJsonPath('data.tables.0.geometry.shape', 'rectangle');
    $respuesta->assertJsonPath('data.tables.0.account', null);
});

it('el piso NO trae importes', function () {
    // El permiso de esta pantalla es `floor.layouts.view`, que tiene todo el que atiende. Pintar el total sobre la
    // mesa concedería por la vía de atrás un permiso que el negocio quizá no dio — y el importe está a un clic.
    app(TenantContext::class)->set($this->tenant->id);
    $mesa = ($this->mesa)('M1');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $mesa->ulid])
        ->assertCreated();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/branches/{$this->branch->ulid}/floor")
        ->assertOk();

    $cuenta = $respuesta->json('data.tables.0.account');

    expect($cuenta)->not->toBeNull();
    expect($cuenta)->toHaveKeys(['ulid', 'folio', 'display_name', 'items_count', 'opened_at']);

    // Ni total, ni pagado, ni lo que falta. La ausencia se comprueba explícitamente: un campo que se cuela más
    // adelante no rompería ninguna otra aserción.
    expect($cuenta)->not->toHaveKey('totals');
    expect(json_encode($respuesta->json('data')))->not->toContain('total');
});

it('una mesa retirada con cuenta encima SIGUE viéndose', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $conCuenta = ($this->mesa)('M1');
    $vacia = ($this->mesa)('M2');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['table_ulid' => $conCuenta->ulid])
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);
    $conCuenta->archive();
    $vacia->archive();
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/branches/{$this->branch->ulid}/floor")
        ->assertOk();

    // La retirada Y vacía se va; la retirada con gente encima se queda hasta que se cobre.
    $respuesta->assertJsonCount(1, 'data.tables');
    $respuesta->assertJsonPath('data.tables.0.code', 'M1');
    $respuesta->assertJsonPath('data.tables.0.is_archived', true);
});

it('una sucursal sin plano lo dice en lugar de reventar', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $otra = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/branches/{$otra->ulid}/floor")
        ->assertNotFound();
});

it('el piso de una sucursal fuera de alcance responde 403', function () {
    // El mismo hueco que D292 cerró en once endpoints, en una superficie nueva. El `tenant_id` no protege: la
    // sucursal ajena es del mismo negocio.
    app(TenantContext::class)->set($this->tenant->id);
    $ajena = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);

    $persona = User::factory()->create(['email' => 'mesero@fonda.mx']);
    $membresia = TenantMembership::factory()->create([
        'user_id' => $persona->id,
        'employee_code' => 'M001',
        'has_all_branches' => false,
    ]);
    $membresia->branchScopes()->create(['branch_id' => $this->branch->id]);

    $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $persona->syncRoles([$rol]);
    $membresia->update(['default_role_id' => $rol->id]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($persona, $this->tenant->id)
        ->getJson("/api/v1/branches/{$this->branch->ulid}/floor")
        ->assertOk();

    $this->actingAsSpa($persona, $this->tenant->id)
        ->getJson("/api/v1/branches/{$ajena->ulid}/floor")
        ->assertForbidden();
});

it('el plano completo trae zonas y mesas para el editor', function () {
    app(TenantContext::class)->set($this->tenant->id);
    ($this->mesa)('M1');
    $retirada = ($this->mesa)('M9');
    $retirada->archive();
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/floor-plans/{$this->plan->ulid}")
        ->assertOk();

    $respuesta->assertJsonPath('data.version', 1);
    $respuesta->assertJsonPath('data.canvas.width', '1200.00');

    // El editor SÍ ve las archivadas: es donde se restauran, y ocultarlas ahí las volvería irrecuperables.
    $respuesta->assertJsonCount(2, 'data.tables');
});

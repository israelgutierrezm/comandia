<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Infrastructure\Models\ReportGoal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * METAS: EL ALCANCE POR SUCURSAL (Iteración 7, Tanda C, D46)
 *
 * Fijar una meta ya comprobaba que su sucursal estuviera al alcance de quien opera; listarlas y borrarlas, no. Un rol
 * acotado a una sucursal veía las metas de las demás y podía borrarlas: la sucursal ajena es del MISMO negocio, así que
 * el `tenant_id` la deja pasar entera — el hueco que documenta `BranchScopeIsAssertedTest`.
 *
 * El criterio es el de `store`: una meta de sucursal exige alcance a ESA sucursal; una consolidada (sin sucursal) la
 * administra cualquiera con `dashboards.goals.manage`, porque no pertenece a ninguna.
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

    // Alguien con alcance a UNA sola sucursal y permiso para administrar metas. Tiene que ser otra persona: la membresía
    // del propietario alcanza todo el negocio y sobre ella no se puede probar nada de esto.
    $rol = Role::create(['name' => 'Metas de sucursal', 'guard_name' => 'web']);
    $rol->givePermissionTo('dashboards.goals.manage');

    $this->acotado = User::factory()->create();

    $membresia = TenantMembership::factory()->create([
        'user_id' => $this->acotado->id,
        'has_all_branches' => false,
        'default_role_id' => $rol->id,
    ]);
    $membresia->branchScopes()->create(['branch_id' => $this->branch->id]);

    $this->acotado->assignRole($rol);

    app(TenantContext::class)->forget();

    // El propietario alcanza todo: fija una meta consolidada, una de su sucursal y una de la ajena.
    $fijar = fn (?string $branchUlid) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/report-goals', [
            'report_key' => 'sales.by_article',
            'measure_key' => 'net_sales',
            'branch_ulid' => $branchUlid,
            'period' => 'month',
            'target_value' => '1000',
            'direction' => 'higher_better',
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->consolidada = $fijar(null);
    $this->propia = $fijar($this->branch->ulid);
    $this->deLaAjena = $fijar($this->ajena->ulid);
});

afterEach(fn () => app(TenantContext::class)->forget());

it('un rol acotado sólo lista las metas consolidadas y las de su sucursal', function () {
    $visibles = $this->actingAsSpa($this->acotado, $this->tenant->id)
        ->getJson('/api/v1/report-goals?report=sales.by_article')
        ->assertOk()
        ->json('data.*.ulid');

    expect($visibles)->toEqualCanonicalizing([$this->consolidada, $this->propia]);

    // Quien alcanza todas las sucursales las sigue viendo todas: el filtro acota, no esconde de más.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/report-goals?report=sales.by_article')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('un rol acotado no borra la meta de una sucursal fuera de su alcance', function () {
    $this->actingAsSpa($this->acotado, $this->tenant->id)
        ->deleteJson("/api/v1/report-goals/{$this->deLaAjena}")
        ->assertForbidden();

    $sigue = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => ReportGoal::query()->where('ulid', $this->deLaAjena)->exists(),
    );

    expect($sigue)->toBeTrue();
});

it('un rol acotado borra la meta de su sucursal y la consolidada, con el criterio de store', function () {
    // La primera aserción importa tanto como la de arriba: sin ella, un 403 por cualquier otro motivo —un permiso que
    // falta, un rol mal sembrado— se leería como que el alcance funciona.
    $this->actingAsSpa($this->acotado, $this->tenant->id)
        ->deleteJson("/api/v1/report-goals/{$this->propia}")
        ->assertNoContent();

    $this->actingAsSpa($this->acotado, $this->tenant->id)
        ->deleteJson("/api/v1/report-goals/{$this->consolidada}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/report-goals?report=sales.by_article')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $this->deLaAjena);
});

it('un negocio no ve ni borra las metas de otro', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/report-goals')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->deleteJson("/api/v1/report-goals/{$this->consolidada}")
        ->assertNotFound();
});

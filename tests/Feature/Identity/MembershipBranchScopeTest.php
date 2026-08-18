<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * ALCANCE POR SUCURSAL DE UNA MEMBRESÍA
 *
 * El endpoint que faltaba: `identity.memberships.manage_branch_scopes` estaba en el catálogo cerrado
 * desde la Iteración 1 y **ninguna ruta lo usaba**. El alcance sólo se podía fijar al dar de alta a la
 * persona; después no había forma de cambiarlo salvo entrando a la base de datos.
 *
 * Lo que estas pruebas cuidan no es el CRUD: es que **«todas las sucursales» y una lista explícita no se
 * puedan mezclar**. No son dos formas de escribir lo mismo — la bandera incluye las sucursales que se
 * abran mañana— y aceptar las dos a la vez dejaría una lista que parece la verdad mientras la bandera la
 * ignora. Eso no se descubre hasta que alguien abre una sucursal nueva y no entiende quién entra.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda de las Sucursales',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Lucía',
        ownerPaternalSurname: 'Beltrán',
        plainPassword: 'contrasena-larga-1',
        branchName: 'Matriz',
        branchCode: 'MTZ',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->matriz = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->polanco = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);
    $this->roma = Branch::factory()->create(['code' => 'ROMA', 'name' => 'Roma']);

    // La persona a la que se le define el alcance: alguien distinto del propietario, que alcanza todo.
    //
    // Sin credenciales y CON perfil de empleado, que es de donde sale su nombre: el invariante I1 (D66)
    // exige uno u otro, y una membresía sin ninguno de los dos no tiene nombre que mostrar. Es además el
    // caso interesante — la cocinera que no inicia sesión pero sí tiene que estar asignada a una
    // sucursal.
    $this->empleado = TenantMembership::factory()->withoutCredentials()->create([
        'employee_code' => 'E010',
        'has_all_branches' => false,
    ]);

    EmployeeProfile::create([
        'membership_id' => $this->empleado->id,
        'legal_first_name' => 'Guadalupe',
        'legal_paternal_surname' => 'Solís',
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('fija el alcance a una lista explícita de sucursales', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => false,
            'branch_ulids' => [$this->polanco->ulid, $this->roma->ulid],
        ])
        ->assertOk()
        ->assertJsonPath('data.has_all_branches', false)
        ->assertJsonCount(2, 'data.branch_scopes');

    app(TenantContext::class)->set($this->tenant->id);

    expect($this->empleado->refresh()->branchScopes()->count())->toBe(2);
});

it('marcar «todas» borra la lista, porque incluye las sucursales futuras', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $this->empleado->branchScopes()->create(['branch_id' => $this->polanco->id]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => true,
            'branch_ulids' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.has_all_branches', true)
        ->assertJsonCount(0, 'data.branch_scopes');

    app(TenantContext::class)->set($this->tenant->id);

    // Las filas se van de verdad. Dejarlas «por si acaso» produciría el estado ambiguo que este
    // endpoint existe para evitar: una lista guardada que la bandera ignora.
    expect($this->empleado->refresh()->branchScopes()->count())->toBe(0);
});

it('rechaza «todas» junto con una lista, en lugar de resolverlo por precedencia', function () {
    // Una precedencia silenciosa es una decisión que el sistema toma por el usuario. Que falle obliga a
    // decir cuál de las dos cosas se quería.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => true,
            'branch_ulids' => [$this->polanco->ulid],
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'validation_error')
        ->assertJsonStructure(['errors' => ['branch_ulids']]);
});

it('rechaza un alcance vacío sin la bandera: nadie opera en ningún sitio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => false,
            'branch_ulids' => [],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['branch_ulids']]);
});

it('una sucursal de otro negocio no existe para este endpoint', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'ajeno@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
        branchCode: 'AJN',
    );

    // El aislamiento no depende de que el cliente mande identificadores válidos: la regla `exists` está
    // acotada al tenant, así que el ULID ajeno se rechaza como inexistente y no se confirma que exista.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => false,
            'branch_ulids' => [$otro['branch']->ulid],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['branch_ulids.0']]);
});

it('deja en la bitácora el antes y el después, con los códigos de sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => false,
            'branch_ulids' => [$this->polanco->ulid],
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = AuditEntry::query()
        ->where('action', AuditAction::BRANCH_SCOPES_UPDATED)
        ->latest('id')
        ->firstOrFail();

    // Los CÓDIGOS y no las llaves internas: la bitácora es evidencia y tiene que poder leerse dentro de
    // un año, cuando quizá esa sucursal ya no exista.
    expect($asiento->after['branches'])->toBe(['POLA'])
        ->and($asiento->after['has_all_branches'])->toBeFalse()
        ->and($asiento->before['branches'])->toBe([]);
});

it('exige el permiso propio: el cajero no define alcances', function () {
    app(TenantContext::class)->set($this->tenant->id);
    app(ProvisionTenantRoles::class)->provision();
    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->firstOrFail();

    // `syncRoles` va DENTRO del contexto: los roles de Spatie usan el tenant como equipo, así que sin
    // contexto no sabe a qué negocio pertenece el rol que asigna.
    $this->owner->syncRoles([$cajero]);

    app(TenantContext::class)->forget();

    // Con `X-Role` y no sólo asignando el rol: la autorización opera por ROL ACTIVO (D9), no por la suma
    // de roles de la persona. Sin la cabecera seguiría entrando como propietario y la prueba pasaría por
    // el motivo equivocado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $cajero->ulid)
        ->putJson("/api/v1/memberships/{$this->empleado->ulid}/branches", [
            'has_all_branches' => false,
            'branch_ulids' => [$this->polanco->ulid],
        ])
        ->assertForbidden();
});

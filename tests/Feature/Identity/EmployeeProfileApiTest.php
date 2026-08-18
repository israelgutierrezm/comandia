<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PERFIL LABORAL POR API
 *
 * ## Estos dos endpoints nunca se habían llamado
 *
 * `GET` y `PUT /memberships/{ulid}/employee-profile` respondían **500 desde la Iteración 1**, con permiso
 * y sin él: el recurso desempaquetaba con `...` el resultado de `mergeWhen`, que es un objeto y no un
 * arreglo.
 *
 * La suite tenía una prueba del `DELETE` —que devuelve 204 y no pasa por el recurso— y ninguna de las
 * otras dos. Es el hueco más silencioso que puede tener una suite: no es una aserción débil, es un
 * endpoint entero sin llamar. Lo encontró el navegador al abrir la pestaña de perfil laboral de la
 * primera persona.
 *
 * ## Lo que estas pruebas fijan
 *
 * El contrato de los datos fiscales, que es una decisión de diseño y no un detalle: la **llave falta**
 * cuando no hay permiso, en lugar de venir en `null`. La ausencia dice «no puedes verlo»; un `null` diría
 * «no hay dato», y mostrar «sin CURP» a quien simplemente no puede verla es mentirle.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Nómina',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Elena',
        ownerPaternalSurname: 'Quintero',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->set($this->tenant->id);

    app(ProvisionTenantRoles::class)->provision();

    // La cocinera que no inicia sesión: su nombre sale del perfil (D66, invariante I1).
    $this->cocinera = TenantMembership::factory()->withoutCredentials()->create(['employee_code' => 'C001']);

    EmployeeProfile::create([
        'membership_id' => $this->cocinera->id,
        'legal_first_name' => 'Josefina',
        'legal_paternal_surname' => 'Aguilar',
        'legal_maternal_surname' => 'Mena',
        'curp' => 'AGMJ850315MDFGNS04',
        'rfc' => 'AGMJ850315AB1',
        'nss' => '12345678901',
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('devuelve el perfil con los datos fiscales cuando el rol activo puede verlos', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/memberships/{$this->cocinera->ulid}/employee-profile")
        ->assertOk()
        ->assertJsonPath('data.legal_name.full', 'Josefina Aguilar Mena')
        ->assertJsonPath('data.curp', 'AGMJ850315MDFGNS04')
        ->assertJsonPath('data.can_view_sensitive', true);
});

it('OMITE la llave de los datos fiscales cuando el rol activo no puede verlos', function () {
    app(TenantContext::class)->set($this->tenant->id);
    // Un rol hecho a mano con exactamente el permiso de ver perfiles, y no una plantilla: así la prueba
    // depende del PERMISO y no de qué reparte cada plantilla. El gerente se define por resta
    // (`managerPermissions()`), así que si mañana cambia la lista de exclusiones, esta prueba seguiría
    // probando lo mismo.
    $rol = Role::create([
        'name' => 'Encargado de nómina',
        'description' => 'Ve perfiles, no datos fiscales.',
    ]);
    $rol->syncPermissions(['identity.users.view', 'identity.employee_profiles.view']);

    $this->owner->syncRoles([$rol]);
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->getJson("/api/v1/memberships/{$this->cocinera->ulid}/employee-profile")
        ->assertOk()
        ->assertJsonPath('data.can_view_sensitive', false);

    // La llave FALTA. No viene en `null`: es la distinción entre «no puedes verlo» y «no hay dato».
    $respuesta->assertJsonMissingPath('data.curp')
        ->assertJsonMissingPath('data.rfc')
        ->assertJsonMissingPath('data.nss')
        ->assertJsonMissingPath('data.birth_date');
});

it('crea el perfil de quien no lo tiene y lo devuelve', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = TenantMembership::factory()->withoutCredentials()->create(['employee_code' => 'M002']);
    EmployeeProfile::create([
        'membership_id' => $mesero->id,
        'legal_first_name' => 'Tomás',
        'legal_paternal_surname' => 'Reyes',
    ]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$mesero->ulid}/employee-profile", [
            'legal_first_name' => 'Tomás',
            'legal_paternal_surname' => 'Reyes',
            'legal_maternal_surname' => 'Ocampo',
            'is_foreigner' => false,
            'hired_at' => '2026-03-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.legal_name.maternal_surname', 'Ocampo')
        ->assertJsonPath('data.hired_at', '2026-03-01');
});

it('rechaza una fecha de baja anterior a la de alta', function () {
    // La validación que evita un periodo laboral imposible, que después haría números negativos en
    // cualquier reporte de antigüedad.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->cocinera->ulid}/employee-profile", [
            'legal_first_name' => 'Josefina',
            'legal_paternal_surname' => 'Aguilar',
            'hired_at' => '2026-05-01',
            'terminated_at' => '2026-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['terminated_at']]);
});

it('el perfil de otro negocio no existe', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'ajeno@cafe.mx',
        ownerFirstName: 'Raúl',
        ownerPaternalSurname: 'Vega',
        plainPassword: 'contrasena-larga-2',
        branchCode: 'AJN',
    );

    // 404 y no 403: no se confirma la existencia de un recurso de otro negocio.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/memberships/{$this->cocinera->ulid}/employee-profile")
        ->assertNotFound();
});

it('el mesero no ve perfiles laborales', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson("/api/v1/memberships/{$this->cocinera->ulid}/employee-profile")
        ->assertForbidden();
});

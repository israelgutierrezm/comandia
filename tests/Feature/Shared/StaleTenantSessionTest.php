<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use Illuminate\Support\Facades\DB;

/**
 * UN NEGOCIO INSERVIBLE EN LA SESIÓN NO ENCIERRA A NADIE
 *
 * ## El defecto
 *
 * Si el negocio guardado en la sesión desaparecía —borrado, o suspendido— el middleware respondía 403 a
 * **todas** las rutas, incluidas `login` y `logout`. La persona quedaba encerrada: no podía entrar a otro
 * negocio ni cerrar sesión, porque cerrar sesión también estaba prohibido. La única salida era borrar las
 * cookies a mano, y la de sesión es `HttpOnly`, así que ni con la consola del navegador.
 *
 * Las rutas de escape existían en la rama de «no hay negocio elegido» y **no** en la de «el negocio no
 * sirve», que es donde más falta hacen: en la primera al usuario no le ha pasado nada; en la segunda ya
 * tiene un problema y necesita una salida.
 *
 * Lo encontró el navegador, y de la manera más tonta: re-sembré el negocio de demostración con una sesión
 * abierta. Es exactamente lo que le pasa a un cliente al que se le suspende la cuenta con la pestaña
 * abierta.
 *
 * ## Por qué se manda a elegir negocio y no a una pantalla de error
 *
 * Porque una persona puede administrar dos restaurantes (§4.1). Que uno esté suspendido no la deja fuera
 * del otro, y una pantalla de error se lo diría.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que se Suspende',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Sofía',
        ownerPaternalSurname: 'Cordero',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('con el negocio suspendido, cerrar sesión sigue funcionando', function () {
    // Suspendido: `allowsAccess()` es false, que es el caso que producía el encierro.
    DB::table('tenants')->where('id', $this->tenant->id)
        ->update(['status' => TenantStatus::Suspended->value]);

    $this->withSession(['tenant_id' => $this->tenant->id])
        ->actingAs($this->owner)
        ->post('/logout')
        ->assertRedirect();

    // Y la sesión se cerró de verdad: no es un redirect de cortesía.
    expect(auth()->check())->toBeFalse();
});

it('con el negocio suspendido, la navegación va a elegir negocio y limpia la sesión', function () {
    DB::table('tenants')->where('id', $this->tenant->id)
        ->update(['status' => TenantStatus::Suspended->value]);

    $respuesta = $this->withSession(['tenant_id' => $this->tenant->id])
        ->actingAs($this->owner)
        ->get('/admin');

    $respuesta->assertRedirect(route('tenants.select'));

    // Se olvida el negocio inservible. Sin esto, la pantalla de selección volvería a resolverlo y el
    // usuario daría vueltas entre dos redirecciones.
    $respuesta->assertSessionMissing('tenant_id');
});

it('la pantalla de elegir negocio se puede abrir con un negocio inservible en la sesión', function () {
    DB::table('tenants')->where('id', $this->tenant->id)
        ->update(['status' => TenantStatus::Suspended->value]);

    $this->withSession(['tenant_id' => $this->tenant->id])
        ->actingAs($this->owner)
        ->withoutVite()
        ->get(route('tenants.select'))
        ->assertOk();
});

it('la pantalla de elegir negocio funciona SIN negocio en la sesión', function () {
    // El caso base, y estaba roto: sin negocio elegido no hay contexto de tenant, y la carga previa del
    // perfil de empleado —un modelo de dominio con scope— lo exigía. O sea que la pantalla que sirve
    // para elegir negocio respondía 500 justo cuando no había ninguno elegido.
    //
    // No se veía porque el inicio de sesión con UNA sola membresía entra directo y nunca pasa por aquí.
    // Sale a la luz con dos negocios, o al quedar la sesión con un negocio que ya no sirve.
    $this->actingAs($this->owner)
        ->withoutVite()
        ->get(route('tenants.select'))
        ->assertOk();
});

it('la pantalla lista a una persona SIN credenciales por su perfil de empleado', function () {
    // El caso que obliga a leer el perfil entre negocios: quien no tiene correo saca su nombre del
    // perfil (D66). Es también el que reventaba.
    app(TenantContext::class)->set($this->tenant->id);

    $membresia = TenantMembership::factory()
        ->withoutCredentials()
        ->create(['employee_code' => 'E900']);

    EmployeeProfile::create([
        'membership_id' => $membresia->id,
        'legal_first_name' => 'Rosario',
        'legal_paternal_surname' => 'Nava',
    ]);

    app(TenantContext::class)->forget();

    $this->actingAs($this->owner)
        ->withoutVite()
        ->get(route('tenants.select'))
        ->assertOk();
});

it('la API sigue devolviendo 403, porque un cliente no navega', function () {
    DB::table('tenants')->where('id', $this->tenant->id)
        ->update(['status' => TenantStatus::Suspended->value]);

    // La diferencia es deliberada: una redirección a una pantalla de selección no significa nada para
    // la app de Flutter, que necesita el código para saber qué pasó.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/context')
        ->assertForbidden();
});

it('una persona con dos negocios entra al que sigue vivo', function () {
    $segundo = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería que Sigue',
        // El MISMO correo: es la premisa de §4.1 — un usuario global con varias membresías.
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Sofía',
        ownerPaternalSurname: 'Cordero',
        plainPassword: 'contrasena-larga-1',
        branchCode: 'CAF',
    );

    expect($segundo['owner']->id)->toBe($this->owner->id, 'El correo debía reutilizar el usuario global.');

    DB::table('tenants')->where('id', $this->tenant->id)
        ->update(['status' => TenantStatus::Suspended->value]);

    // Con el negocio suspendido en la sesión, se puede elegir el otro y operar.
    $this->withSession(['tenant_id' => $this->tenant->id])
        ->actingAs($this->owner)
        ->post(route('tenants.enter'), ['tenant_ulid' => $segundo['tenant']->ulid])
        ->assertRedirect();

    /** @var User $owner */
    $owner = $this->owner;

    $this->actingAsSpa($owner, $segundo['tenant']->id)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->assertJsonPath('data.tenant.name', 'Cafetería que Sigue');
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Super administración de la plataforma: acceso aislado y alta de negocios.
 *
 * El super admin vive en su propia tabla y guard (`platform`), del todo aparte del personal de los negocios: un
 * usuario de negocio no entra aquí y un super admin no opera negocios. Estas pruebas vigilan ese aislamiento y el
 * «cargar instancias nuevas».
 */
function superAdmin(string $email = 'ceo@comandia.mx', string $password = 'plataforma-123'): PlatformAdmin
{
    return PlatformAdmin::create(['name' => 'Operador', 'email' => $email, 'password' => $password]);
}

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('muestra el acceso a quien no ha entrado', function () {
    $this->withoutVite()->get('/plataforma/acceso')->assertOk();
});

it('un super admin entra con sus credenciales', function () {
    superAdmin();

    $this->post('/plataforma/acceso', ['email' => 'ceo@comandia.mx', 'password' => 'plataforma-123'])
        ->assertRedirect('/plataforma');

    expect(auth('platform')->check())->toBeTrue();
});

it('rechaza credenciales equivocadas con un mensaje genérico', function () {
    superAdmin();

    $this->from('/plataforma/acceso')
        ->post('/plataforma/acceso', ['email' => 'ceo@comandia.mx', 'password' => 'incorrecta'])
        ->assertRedirect('/plataforma/acceso')
        ->assertSessionHasErrors('email');

    expect(auth('platform')->check())->toBeFalse();
});

it('sin sesión de plataforma, el panel manda al acceso', function () {
    $this->get('/plataforma')->assertRedirect('/plataforma/acceso');
    $this->get('/plataforma/negocios')->assertRedirect('/plataforma/acceso');
    $this->get('/plataforma/negocios/nuevo')->assertRedirect('/plataforma/acceso');
});

it('un usuario de NEGOCIO no entra a la plataforma (aislamiento)', function () {
    // Autenticado en el guard de negocios (`web`), NO en el de plataforma: el guardián de plataforma no lo reconoce.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plataforma')->assertRedirect('/plataforma/acceso');
    $this->actingAs($user)->get('/plataforma/negocios')->assertRedirect('/plataforma/acceso');
});

it('el tablero cuenta los negocios', function () {
    $props = $this->actingAs(superAdmin(), 'platform')->withoutVite()
        ->get('/plataforma')->viewData('page')['props'];

    expect($props)->toHaveKeys(['total', 'by_status']);
});

it('lista los negocios de la plataforma', function () {
    app(ProvisionTenant::class)->provision(
        businessName: 'Fonda Uno',
        ownerEmail: 'a@uno.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Uno',
        plainPassword: 'secreto-largo-1',
    );
    app(TenantContext::class)->forget();

    $props = $this->actingAs(superAdmin(), 'platform')->withoutVite()
        ->get('/plataforma/negocios')->viewData('page')['props'];

    expect(collect($props['businesses'])->pluck('name'))->toContain('Fonda Uno');
});

it('muestra el formulario de alta', function () {
    $this->actingAs(superAdmin(), 'platform')->withoutVite()
        ->get('/plataforma/negocios/nuevo')->assertOk();
});

it('da de alta un negocio con su dueño y su sucursal', function () {
    $this->actingAs(superAdmin(), 'platform')
        ->post('/plataforma/negocios', [
            'business_name' => 'Café Nuevo',
            'owner_email' => 'due@cafe.mx',
            'owner_first_name' => 'Diana',
            'owner_paternal_surname' => 'Nuevo',
            'plain_password' => 'contrasena-8',
        ])
        ->assertRedirect('/plataforma/negocios')
        ->assertSessionHas('success');

    expect(Tenant::query()->where('name', 'Café Nuevo')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'due@cafe.mx')->exists())->toBeTrue();
});

it('el alta valida sus datos', function () {
    $this->actingAs(superAdmin(), 'platform')
        ->from('/plataforma/negocios/nuevo')
        ->post('/plataforma/negocios', ['business_name' => '', 'owner_email' => 'no-es-correo', 'plain_password' => 'corta'])
        ->assertRedirectToRoute('platform.businesses.create')
        ->assertSessionHasErrors(['business_name', 'owner_email', 'plain_password']);
});

it('salir cierra la sesión de plataforma', function () {
    $this->actingAs(superAdmin(), 'platform')
        ->post('/plataforma/salir')->assertRedirect('/plataforma/acceso');
});

it('el comando crea un super administrador', function () {
    $this->artisan('comandia:platform-admin', ['email' => 'nuevo@comandia.mx', '--password' => 'plataforma-123'])
        ->assertSuccessful();

    expect(PlatformAdmin::query()->where('email', 'nuevo@comandia.mx')->exists())->toBeTrue();
});

it('el comando rechaza una contraseña demasiado corta', function () {
    $this->artisan('comandia:platform-admin', ['email' => 'x@comandia.mx', '--password' => 'corta'])
        ->assertFailed();

    expect(PlatformAdmin::query()->where('email', 'x@comandia.mx')->exists())->toBeFalse();
});

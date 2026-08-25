<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;

function negocioPendiente(string $name = 'Fonda Nueva'): Tenant
{
    $resultado = app(ProvisionTenant::class)->provision(
        businessName: $name,
        ownerEmail: 'dueno-'.bin2hex(random_bytes(4)).'@negocio.mx',
        ownerFirstName: 'Dueño',
        ownerPaternalSurname: 'Prueba',
        plainPassword: 'secreto-largo-1',
    );

    app(TenantContext::class)->forget();

    return $resultado['tenant'];
}

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

it('el tablero cuenta los negocios y lista las altas recientes', function () {
    negocioPendiente('Recién Creado');

    $props = $this->actingAs(superAdmin(), 'platform')->withoutVite()
        ->get('/plataforma')->viewData('page')['props'];

    expect($props)->toHaveKeys(['total', 'by_status', 'recent'])
        ->and(collect($props['recent'])->pluck('name'))->toContain('Recién Creado');
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

// --- Fase 2: transiciones de estado ---

it('muestra el detalle de un negocio con sus acciones legales', function () {
    $tenant = negocioPendiente('Café del Sur');

    $props = $this->actingAs(superAdmin(), 'platform')->withoutVite()
        ->get("/plataforma/negocios/{$tenant->ulid}")->viewData('page')['props'];

    expect($props['business']['name'])->toBe('Café del Sur')
        // Desde «pendiente de activación» se puede activar o suspender, pero NO poner en sólo lectura.
        ->and(collect($props['allowed'])->pluck('value')->all())->toEqualCanonicalizing(['active', 'suspended'])
        // Resumen (Fase 3): un negocio recién aprovisionado tiene su primera sucursal y su dueño activo.
        ->and($props['summary']['branches'])->toBe(1)
        ->and($props['summary']['staff'])->toBe(1);
});

it('activa un negocio y lo registra en el historial con el actor de plataforma', function () {
    $tenant = negocioPendiente();
    $admin = superAdmin();

    $this->actingAs($admin, 'platform')
        ->post("/plataforma/negocios/{$tenant->ulid}/estado", ['status' => 'active', 'reason' => 'Pago confirmado'])
        ->assertRedirect("/plataforma/negocios/{$tenant->ulid}")
        ->assertSessionHas('success');

    expect($tenant->refresh()->status->value)->toBe('active');

    $transicion = app(TenantContext::class)->runFor(
        $tenant->id,
        fn () => TenantStatusTransition::query()->where('to_status', 'active')->latest('id')->first(),
    );
    app(TenantContext::class)->forget();

    expect($transicion)->not->toBeNull()
        ->and($transicion->actor_platform_admin_id)->toBe($admin->id)
        ->and($transicion->reason)->toBe('Pago confirmado');
});

it('rechaza una transición ilegal sin cambiar el estado', function () {
    // Un negocio «pendiente de activación» no puede pasar directo a «sólo lectura»: el servicio lo rechaza y la
    // pantalla vuelve con el aviso, sin tocar el estado.
    $tenant = negocioPendiente();

    $this->actingAs(superAdmin(), 'platform')
        ->from("/plataforma/negocios/{$tenant->ulid}")
        ->post("/plataforma/negocios/{$tenant->ulid}/estado", ['status' => 'read_only'])
        ->assertRedirect("/plataforma/negocios/{$tenant->ulid}")
        ->assertSessionHas('error');

    expect($tenant->refresh()->status->value)->toBe('pending_activation');
});

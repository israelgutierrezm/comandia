<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Inicio de sesión y selección de negocio (§2, §4.1).
 *
 * El caso que hace interesante este flujo es la persona con dos restaurantes: la identidad es global
 * al SaaS y la pertenencia es por tenant, así que autenticarse y elegir negocio son dos pasos.
 */
beforeEach(function () {
    RateLimiter::clear('login:ana@fonda.mx|127.0.0.1');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('muestra la pantalla de entrada a quien no ha iniciado sesión', function () {
    $this->withoutVite()->get('/login')->assertOk();
});

it('entra y va directo al panel con una sola membresía', function () {
    // Con un solo negocio no hay nada que elegir: preguntarlo sería fricción pura.
    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'contrasena-larga-1'])
        ->assertRedirect('/admin');

    expect(Auth::check())->toBeTrue();
});

it('el asiento del inicio de sesión NOMBRA a quien entró', function () {
    // No basta con que el asiento exista: su único propósito es decir quién entró. El actor sale
    // normalmente del contexto de la petición, y en el login ese contexto está VACÍO —la
    // autenticación es global al SaaS y el negocio se resuelve después (§4.1)—, así que el asiento
    // quedaba atribuido a «Sistema». La versión anterior de este test sólo comprobaba `exists()` y
    // por eso pasaba en verde con el defecto puesto; lo encontró la pantalla de auditoría.
    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'contrasena-larga-1']);

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = AuditEntry::query()->where('action', AuditAction::LOGIN)->firstOrFail();

    expect($asiento->actor_user_id)->toBe($this->owner->id);
    expect($asiento->actor_membership_id)->not->toBeNull();
});

it('el asiento de un intento fallido también nombra a la persona', function () {
    // Sin actor, el reporte de «cinco fallos seguidos sobre esta persona» (§6.7) no puede agrupar.
    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta']);

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = AuditEntry::query()->where('action', AuditAction::LOGIN_FAILED)->firstOrFail();

    expect($asiento->actor_user_id)->toBe($this->owner->id);
});

it('no distingue correo inexistente de contraseña incorrecta', function () {
    // Distinguirlos permitiría averiguar qué correos están registrados en el SaaS.
    $inexistente = $this->post('/login', ['email' => 'nadie@ejemplo.mx', 'password' => 'x'])
        ->assertSessionHasErrors('email');

    RateLimiter::clear('login:ana@fonda.mx|127.0.0.1');

    $malaClave = $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))
        ->toBe('Las credenciales no coinciden con nuestros registros.');
});

it('registra los intentos fallidos: cinco seguidos son la señal', function () {
    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta']);

    app(TenantContext::class)->set($this->tenant->id);

    expect(AuditEntry::query()->where('action', AuditAction::LOGIN_FAILED)->exists())->toBeTrue();
});

it('limita los intentos por correo Y por IP', function () {
    // Sólo por IP, un atacante distribuido no tendría freno sobre una cuenta; sólo por correo,
    // alguien podría bloquear la cuenta de otro a propósito (D55).
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta']);
    }

    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'contrasena-larga-1'])
        ->assertSessionHasErrors('email');

    // Ni con la contraseña correcta: el bloqueo es por intentos, no por credencial.
    expect(Auth::check())->toBeFalse();
});

it('pide elegir negocio a quien pertenece a varios', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'contrasena-larga-1',
    );

    app(TenantContext::class)->forget();

    $this->post('/login', ['email' => 'ana@fonda.mx', 'password' => 'contrasena-larga-1'])
        ->assertRedirect('/negocios');
});

it('entrar a un negocio fija el contexto de la sesión', function () {
    $this->actingAs($this->owner)
        ->post('/negocios', ['tenant_ulid' => $this->tenant->ulid])
        ->assertRedirect('/admin');

    expect(session('tenant_id'))->toBe($this->tenant->id);
});

it('RECHAZA entrar a un negocio donde no se tiene membresía', function () {
    $ajeno = Tenant::factory()->create();

    $this->actingAs($this->owner)
        ->post('/negocios', ['tenant_ulid' => $ajeno->ulid])
        ->assertSessionHasErrors('tenant_ulid');

    expect(session('tenant_id'))->toBeNull();
});

it('una navegación sin negocio elegido redirige, no da error', function () {
    // En la SPA, "falta elegir negocio" es un paso del flujo. Un 409 dejaría al usuario en una
    // pantalla de error en lugar de en la que resuelve su problema.
    $this->actingAs($this->owner)
        ->withoutVite()
        ->get('/admin')
        ->assertRedirect('/negocios');
});

it('la API sí responde 409 sin negocio elegido', function () {
    // Un cliente que llama sin haber elegido negocio tiene un error de flujo y necesita enterarse.
    $this->actingAs($this->owner)
        ->getJson('/api/v1/context')
        ->assertStatus(409);
});

it('el shell comparte el contexto y los permisos del rol activo', function () {
    $respuesta = $this->actingAs($this->owner)
        ->withSession(['tenant_id' => $this->tenant->id])
        ->withoutVite()
        ->get('/admin');

    $respuesta->assertOk();

    $props = $respuesta->viewData('page')['props'];

    expect($props['context']['tenant']['name'])->toBe('Fonda del Centro');
    expect($props['context']['membership']['display_name'])->toBe('Ana Gómez');
    expect($props['context']['role_name'])->toBe(RoleTemplates::OWNER);
    expect($props['context']['is_read_only'])->toBeFalse();

    // De aquí saca `v-can` su verdad: son los permisos del ROL ACTIVO, no la suma de roles (D9).
    expect($props['permissions'])->toContain('organization.branches.manage');
});

it('el shell NO comparte datos de dominio', function () {
    // Frontera de D59: Inertia entrega el shell y los datos vienen de /api/v1. Si el shell trajera
    // sucursales o personal, la app Flutter consumiría endpoints que la web no usa — y ésos serían
    // los menos ejercitados.
    $props = $this->actingAs($this->owner)
        ->withSession(['tenant_id' => $this->tenant->id])
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props)->not->toHaveKeys(['branches', 'warehouses', 'memberships', 'roles', 'settings']);
});

it('los permisos compartidos son los del rol activo, no los de otro rol', function () {
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $props = $this->actingAs($this->owner)
        ->withSession(['tenant_id' => $this->tenant->id])
        ->withHeader('X-Role', $mesero->ulid)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props['permissions'])->toContain('pos.orders.create');
    expect($props['permissions'])->not->toContain('organization.branches.manage');
});

it('el contexto lista los roles asignados para poder cambiar de rol activo', function () {
    // Sin esta lista el selector del shell sólo podía ofrecer el rol ya activo. Listar los roles
    // asignados NO contradice D9: los permisos que viajan siguen siendo los de UN rol —el activo—,
    // y el servidor revalida la elección contra el pivote.
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/context')
        ->assertOk()
        ->json('data');

    $nombres = array_column($datos['assigned_roles'], 'name');

    expect($nombres)->toContain(RoleTemplates::OWNER, RoleTemplates::WAITER);

    // Y los permisos NO son la suma: siguen siendo los del rol activo.
    expect($datos['permissions'])->toContain('organization.branches.manage');

    $comoMesero = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/context')
        ->json('data');

    expect($comoMesero['permissions'])->not->toContain('organization.branches.manage');
});

it('cerrar sesión la invalida y audita', function () {
    $this->actingAs($this->owner)
        ->withSession(['tenant_id' => $this->tenant->id])
        ->post('/logout')
        ->assertRedirect('/login');

    expect(Auth::check())->toBeFalse();

    app(TenantContext::class)->set($this->tenant->id);

    expect(AuditEntry::query()->where('action', AuditAction::LOGOUT)->exists())->toBeTrue();
});

it('una cuenta sin membresía activa no entra', function () {
    User::factory()->create(['email' => 'huerfano@ejemplo.mx']);

    $this->post('/login', ['email' => 'huerfano@ejemplo.mx', 'password' => 'password'])
        ->assertSessionHasErrors('email');

    expect(Auth::check())->toBeFalse();
});

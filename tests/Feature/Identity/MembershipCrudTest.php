<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\IssueApiToken;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use App\Modules\Tenancy\Infrastructure\Models\TenantLimit;

/**
 * Alta y administración de personal (§4.1).
 *
 * Los casos que de verdad pueden salir mal: el empleado sin credenciales y su invariante, el
 * límite de usuarios medido, el auto-bloqueo, y el PII detrás de su permiso.
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
    $this->ownerMembership = $alta['membership'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Alta con credenciales
// ---------------------------------------------------------------------------

it('da de alta a una persona con acceso al sistema', function () {
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx',
            'password' => 'contrasena-larga-1',
            'first_name' => 'Luis',
            'paternal_surname' => 'Pérez',
            'maternal_surname' => 'Soto',
            'employee_code' => 'm010',
            'role_ulids' => [$mesero->ulid],
            'branch_ulids' => [$this->branch->ulid],
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('data.display_name', 'Luis Pérez')
        ->assertJsonPath('data.employee_code', 'M010')
        ->assertJsonPath('data.has_credentials', true)
        // Nace INVITADA: la persona todavía no ha entrado.
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.has_pin', false)
        ->assertJsonPath('data.default_role.name', RoleTemplates::WAITER);
});

it('rechaza dar de alta dos veces a la misma persona en el mismo negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'ana@fonda.mx',
            'password' => 'contrasena-larga-1',
            'first_name' => 'Ana',
            'paternal_surname' => 'Gómez',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');
});

it('reutiliza el usuario global cuando la persona ya trabaja en otro negocio', function () {
    // Correo único en todo el SaaS: una persona con dos restaurantes tiene un solo usuario (§4.1).
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'beto@cafe.mx',
            'password' => 'contrasena-larga-1',
            'first_name' => 'Beto',
            'paternal_surname' => 'Luna',
        ])
        ->assertCreated();

    // Un solo usuario, dos membresías.
    expect($otro['owner']->fresh()->membershipsAcrossTenants()->count())->toBe(2);
});

it('exige contraseña si se da correo', function () {
    // Un usuario nuevo sin contraseña sería una cuenta inaccesible que parece funcional.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'sin@clave.mx',
            'first_name' => 'Sin',
            'paternal_surname' => 'Clave',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'Una persona con acceso al sistema necesita contraseña.');
});

// ---------------------------------------------------------------------------
// Empleado SIN credenciales — invariante I1 (D66)
// ---------------------------------------------------------------------------

it('da de alta al empleado sin credenciales con su perfil', function () {
    // El lavaloza en nómina que jamás inicia sesión (§4.1).
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'first_name' => 'Rosa',
            'paternal_surname' => 'Martínez',
            'employee_code' => 'L001',
            'employee_profile' => [
                'legal_first_name' => 'Rosa',
                'legal_paternal_surname' => 'Martínez',
                'legal_maternal_surname' => 'Díaz',
            ],
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('data.has_credentials', false)
        // Nace ACTIVA: no hay nada que aceptar, existe para nómina y reportes.
        ->assertJsonPath('data.status', 'active')
        // Y tiene nombre, que es el punto del invariante.
        ->assertJsonPath('data.display_name', 'Rosa Martínez')
        ->assertJsonPath('data.email', null);
});

it('RECHAZA una persona sin correo y sin perfil de empleado', function () {
    // Sería una persona sin nombre: una comanda sin mesero identificable y una fila de auditoría
    // que no dice quién actuó.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'first_name' => 'Nadie',
            'paternal_surname' => 'Sinperfil',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.employee_profile.0', 'Una persona sin correo necesita perfil de empleado: es de donde sale su nombre.');
});

it('no permite borrar el perfil de quien no tiene credenciales', function () {
    // La mitad simétrica del invariante I1, la que suele faltar.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'first_name' => 'Rosa',
            'paternal_surname' => 'Martínez',
            'employee_profile' => [
                'legal_first_name' => 'Rosa',
                'legal_paternal_surname' => 'Martínez',
            ],
        ]);

    $ulid = $respuesta->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/memberships/{$ulid}/employee-profile")
        ->assertStatus(409);
});

it('una persona sin credenciales no puede tener roles ni PIN', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'first_name' => 'Rosa', 'paternal_surname' => 'Martínez',
            'employee_code' => 'L001',
            'employee_profile' => ['legal_first_name' => 'Rosa', 'legal_paternal_surname' => 'Martínez'],
        ]);

    $ulid = $respuesta->json('data.ulid');

    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$ulid}/roles", ['role_ulids' => [$mesero->ulid]])
        ->assertStatus(409);

    // Y sin roles, un PIN no podría autorizar nada: darlo sería prometer una capacidad inexistente.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$ulid}/pin", ['pin' => '1234', 'pin_confirmation' => '1234'])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// Límite de usuarios, medido (D4)
// ---------------------------------------------------------------------------

it('respeta el límite de usuarios del plan', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => TenantLimit::create(['limit_key' => TenantLimitKey::MaxUsers, 'limit_value' => 1])
    );

    // El propietario ya ocupa la única plaza.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
        ])
        ->assertStatus(409);
});

it('una baja libera plaza de inmediato, porque el uso se mide', function () {
    // Con un contador almacenado esto sería la operación que lo desincroniza.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => TenantLimit::create(['limit_key' => TenantLimitKey::MaxUsers, 'limit_value' => 2])
    );

    $segundo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
        ])->assertCreated()->json('data.ulid');

    // Está invitada, así que todavía no ocupa plaza; se activa para que sí la ocupe.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$segundo}/reactivate")->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'tres@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Tres', 'paternal_surname' => 'Tercero',
        ])->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$segundo}/suspend")->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'tres@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Tres', 'paternal_surname' => 'Tercero',
        ])->assertCreated();
});

// ---------------------------------------------------------------------------
// Candados contra auto-bloqueo
// ---------------------------------------------------------------------------

it('nadie se suspende a sí mismo', function () {
    // En un negocio con un solo administrador, permitirlo deja el sistema inaccesible con un clic
    // y la recuperación exige tocar la base de datos.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$this->ownerMembership->ulid}/suspend")
        ->assertStatus(409);
});

it('nadie cambia sus propios roles', function () {
    // Evita el auto-bloqueo y, sobre todo, la escalada silenciosa: quien puede asignar roles
    // podría concederse cualquier permiso del catálogo sin que nadie más participe.
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->ownerMembership->ulid}/roles", ['role_ulids' => [$mesero->ulid]])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// PIN
// ---------------------------------------------------------------------------

it('asigna PIN y nunca lo devuelve', function () {
    // El propietario ya tiene código de empleado del alta (P001).
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->ownerMembership->ulid}/pin", [
            'pin' => '4321', 'pin_confirmation' => '4321',
        ]);

    $respuesta->assertOk()->assertJsonPath('data.has_pin', true);

    // Ni el PIN ni su hash aparecen en la respuesta.
    expect(json_encode($respuesta->json()))->not->toContain('4321');

    app(TenantContext::class)->set($this->tenant->id);
    $entrada = AuditEntry::query()->where('action', AuditAction::PIN_RESET)->firstOrFail();
    expect(json_encode($entrada->after))->not->toContain('4321');
});

it('exige código de empleado antes del PIN', function () {
    // Con D84 el autorizador se identifica por código: sin él el PIN sería inutilizable, y
    // descubrirlo con el cliente delante es peor que no poder asignarlo.
    $sinCodigo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
        ])->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$sinCodigo}/pin", ['pin' => '1111', 'pin_confirmation' => '1111'])
        ->assertStatus(409);
});

it('exige confirmar el PIN', function () {
    // El PIN no se puede recuperar ni mostrar: un dedo torcido dejaría a la persona sin poder
    // autorizar y sin saber por qué.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->ownerMembership->ulid}/pin", [
            'pin' => '4321', 'pin_confirmation' => '1234',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.pin_confirmation.0', 'Los PIN no coinciden.');
});

it('acepta un PIN que empieza por cero', function () {
    // Validado como cadena: como entero perdería el cero y la persona no podría entrar con el PIN
    // que le dieron.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/memberships/{$this->ownerMembership->ulid}/pin", [
            'pin' => '0123', 'pin_confirmation' => '0123',
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(Hash::check('0123', (string) $this->ownerMembership->fresh()->pin_hash))->toBeTrue();
});

it('desbloquea el PIN sin cambiarlo', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->ownerMembership->forceFill([
            'pin_hash' => Hash::make('4321'),
            'pin_set_at' => now(),
            'pin_failed_attempts' => 5,
            'pin_locked_until' => now()->addMinutes(15),
        ])->save();
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$this->ownerMembership->ulid}/pin/unlock")
        ->assertOk()
        ->assertJsonPath('data.pin_locked', false)
        ->assertJsonPath('data.has_pin', true);

    // Sigue siendo el mismo PIN: forzar un cambio obligaría al gerente a conocer el PIN nuevo de
    // otra persona.
    app(TenantContext::class)->set($this->tenant->id);
    expect(Hash::check('4321', (string) $this->ownerMembership->fresh()->pin_hash))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Aislamiento
// ---------------------------------------------------------------------------

it('el personal de otro negocio es invisible', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/memberships')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/memberships/{$otro['membership']->ulid}")
        ->assertNotFound();
});

it('un mesero no puede administrar personal', function () {
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->owner->assignRole($mesero));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/memberships')
        ->assertForbidden();
});

it('el código de empleado es único por negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
            'employee_code' => 'P001',
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.employee_code.0', 'Ya existe alguien con ese código de empleado.');
});

it('suspender a alguien invalida sus tokens de este negocio', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'email' => 'luis@fonda.mx', 'password' => 'contrasena-larga-1',
            'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
        ])->json('data.ulid');

    $membresia = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): TenantMembership => TenantMembership::findByUlid($ulid)
    );

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($membresia): void {
        $membresia->update(['status' => 'active']);
        app(IssueApiToken::class)->issue($membresia->refresh(), 'tableta');
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$ulid}/suspend")
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect($membresia->refresh()->user->tokens()->count())->toBe(0);
});

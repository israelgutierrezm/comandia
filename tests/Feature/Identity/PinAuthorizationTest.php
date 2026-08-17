<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationFailed;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * AUTORIZACIÓN POR PIN — verificación de ADR-008 y sus cinco límites.
 *
 * Escenario: un mesero quiere aplicar un descuento y no tiene el permiso. Llama al gerente,
 * que teclea su código de empleado y su PIN en la terminal del mesero y se va.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();

    // ---- El gerente, que SÍ puede autorizar descuentos ----
    $this->rolGerente = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);
    $this->rolGerente->givePermissionTo('pos.discounts.apply_account');

    // Además tiene un segundo rol estrecho. Es lo que hace de esta prueba una verificación
    // de ADR-008 y no sólo de "el PIN funciona": el permiso viene de la UNIÓN de sus roles.
    $this->rolMesero = Role::create(['name' => 'Mesero', 'guard_name' => 'web']);
    $this->rolMesero->givePermissionTo('pos.orders.create');

    $this->gerente = User::factory()->create(['first_name' => 'Luisa', 'paternal_surname' => 'Ortega']);
    $this->membresiaGerente = TenantMembership::factory()->withPin('4321')->create([
        'user_id' => $this->gerente->id,
        'employee_code' => 'G001',
        'default_role_id' => $this->rolMesero->id,
        'has_all_branches' => true,
    ]);
    $this->gerente->assignRole($this->rolGerente, $this->rolMesero);

    // ---- El mesero, dueño de la sesión de la terminal ----
    $this->mesero = User::factory()->create();
    $this->membresiaMesero = TenantMembership::factory()->create([
        'user_id' => $this->mesero->id,
        'employee_code' => 'M001',
        'default_role_id' => $this->rolMesero->id,
        'has_all_branches' => true,
    ]);
    $this->mesero->assignRole($this->rolMesero);

    app(ContextHolder::class)->set(RequestContext::forMember(
        tenant: $this->tenant,
        user: $this->mesero,
        membership: $this->membresiaMesero,
        activeRole: $this->rolMesero,
        activeBranch: $this->branch,
    ));

    $this->pin = app(PinAuthorizationService::class);
});

afterEach(function () {
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

it('concede la autorización con el código y el PIN correctos', function () {
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    expect($grant->permission)->toBe('pos.discounts.apply_account');
    expect($grant->authorizerUlid)->toBe($this->membresiaGerente->ulid);
    // La terminal muestra el nombre: hace visible quién quedó registrado como actor real y
    // es un desincentivo directo a compartir PIN.
    expect($grant->authorizerName)->toBe('Luisa Ortega');
    expect($grant->secondsToExpire())->toBeGreaterThan(0);
});

it('ES LA EXCEPCIÓN DE ADR-008: el permiso sale de la unión de roles, no del rol por defecto', function () {
    // El rol POR DEFECTO del gerente es Mesero, que NO puede aplicar descuentos. Con la
    // regla de rol activo esto sería un "no autorizado" a alguien que sí tiene el permiso, y
    // el tenant lo resolvería dándole rol de gerente a todo el mundo.
    expect($this->membresiaGerente->default_role_id)->toBe($this->rolMesero->id);

    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    expect($grant->permission)->toBe('pos.discounts.apply_account');
});

it('rechaza un PIN incorrecto', function () {
    expect(fn () => $this->pin->grant('G001', '0000', 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('no distingue entre empleado inexistente y PIN incorrecto', function () {
    // Distinguirlos convertiría el endpoint en un oráculo para enumerar códigos válidos.
    $inexistente = null;
    $malPin = null;

    try {
        $this->pin->grant('NOEXISTE', '4321', 'pos.discounts.apply_account');
    } catch (PinAuthorizationFailed $e) {
        $inexistente = $e->getMessage();
    }

    try {
        $this->pin->grant('G001', '0000', 'pos.discounts.apply_account');
    } catch (PinAuthorizationFailed $e) {
        $malPin = $e->getMessage();
    }

    expect($inexistente)->toBe($malPin);
});

it('rechaza cuando el autorizador no tiene el permiso, con el mismo mensaje', function () {
    // El mesero tiene PIN pero no puede autorizar descuentos. Que el mensaje sea idéntico
    // impide averiguar quién puede autorizar qué tecleando al azar.
    $this->membresiaMesero->forceFill(['pin_hash' => Hash::make('1111'), 'pin_set_at' => now()])->save();

    try {
        $this->pin->grant('M001', '1111', 'pos.discounts.apply_account');
        $this->fail('Debió rechazar.');
    } catch (PinAuthorizationFailed $e) {
        expect($e->getMessage())->toBe('Código de empleado o PIN incorrectos.');
    }
});

it('bloquea el PIN tras agotar los intentos y lo audita', function () {
    // Por defecto son 5 intentos (security.pin_max_attempts).
    for ($i = 0; $i < 5; $i++) {
        rescue(fn () => $this->pin->grant('G001', '0000', 'pos.discounts.apply_account'), report: false);
    }

    expect($this->membresiaGerente->fresh()->isPinLocked())->toBeTrue();

    expect(AuditEntry::query()->where('action', AuditAction::PIN_LOCKED)->exists())->toBeTrue();

    // Y ya no funciona ni con el PIN correcto.
    expect(fn () => $this->pin->grant('G001', '4321', 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('un intento sin permiso NO cuenta como fallo de PIN', function () {
    // El PIN no falló: el gerente se equivocó de acción. Bloquearlo sería castigar lo que no
    // es un ataque, y con el cliente delante.
    rescue(fn () => $this->pin->grant('G001', '4321', 'inventory.counts.close'), report: false);

    expect($this->membresiaGerente->fresh()->pin_failed_attempts)->toBe(0);
});

it('un PIN correcto reinicia el contador de intentos', function () {
    rescue(fn () => $this->pin->grant('G001', '0000', 'pos.discounts.apply_account'), report: false);
    expect($this->membresiaGerente->fresh()->pin_failed_attempts)->toBe(1);

    $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    expect($this->membresiaGerente->fresh()->pin_failed_attempts)->toBe(0);
});

it('AUDITA SIEMPRE, con los dos actores diferenciados', function () {
    $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    $entrada = AuditEntry::query()
        ->where('action', AuditAction::PIN_AUTHORIZATION_GRANTED)
        ->firstOrFail();

    // La distinción que hace posible el reporte de robo hormiga (§9): quien ejecuta es el
    // mesero, quien autoriza es el gerente.
    expect($entrada->actor_membership_id)->toBe($this->membresiaMesero->id);
    expect($entrada->authorized_by_membership_id)->toBe($this->membresiaGerente->id);
    expect($entrada->wasAuthorizedByAnother())->toBeTrue();

    // Y el rol activo del ejecutor, porque sin él la pregunta "¿podía hacerlo?" no tiene
    // respuesta reproducible.
    expect($entrada->active_role_id)->toBe($this->rolMesero->id);
});

it('audita también las denegadas', function () {
    // Los fallos son la señal: cinco intentos sobre el mismo código son un patrón, y sin
    // registrarlos no existe.
    rescue(fn () => $this->pin->grant('G001', '0000', 'pos.discounts.apply_account'), report: false);

    expect(AuditEntry::query()->where('action', AuditAction::PIN_AUTHORIZATION_DENIED)->exists())
        ->toBeTrue();
});

it('la autorización es de UN SOLO USO', function () {
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    $autorizador = $this->pin->consume($grant->token, 'pos.discounts.apply_account');
    expect($autorizador->id)->toBe($this->membresiaGerente->id);

    // El segundo uso falla: la terminal queda abierta, la autorización no.
    expect(fn () => $this->pin->consume($grant->token, 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('la autorización está ligada a la acción concreta', function () {
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    // No sirve para otra cosa aunque el autorizador también pudiera hacerla.
    expect(fn () => $this->pin->consume($grant->token, 'pos.items.cancel_commanded'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('la autorización expira', function () {
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    $this->travel(3)->minutes();

    expect(fn () => $this->pin->consume($grant->token, 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('deja de servir si la membresía se suspende entre concesión y uso', function () {
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    $this->membresiaGerente->update(['status' => 'suspended']);

    expect(fn () => $this->pin->consume($grant->token, 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('una membresía suspendida no puede autorizar', function () {
    $this->membresiaGerente->update(['status' => 'suspended']);

    expect(fn () => $this->pin->grant('G001', '4321', 'pos.discounts.apply_account'))
        ->toThrow(PinAuthorizationFailed::class);
});

it('el PIN de otro tenant no sirve aquí', function () {
    // El PIN de un tenant no es el PIN de otro (§4.1). Mismo código de empleado y mismo PIN
    // en otro negocio: no autoriza nada aquí.
    $otro = Tenant::factory()->create();

    app(TenantContext::class)->runFor($otro->id, function (): void {
        $usuario = User::factory()->create();
        TenantMembership::factory()->withPin('4321')->create([
            'user_id' => $usuario->id,
            'employee_code' => 'G001',
        ]);
    });

    // Sigue funcionando el del tenant activo, y sólo ése.
    $grant = $this->pin->grant('G001', '4321', 'pos.discounts.apply_account');

    expect($grant->authorizerUlid)->toBe($this->membresiaGerente->ulid);
});

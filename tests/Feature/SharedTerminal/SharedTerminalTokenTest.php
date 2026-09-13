<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TERMINAL COMPARTIDA POR TOKEN (ADR-014) — EL KIOSCO MÓVIL
 *
 * El mismo modelo de ADR-012 (un DISPOSITIVO enrolado sobre el que cada operador teclea código + PIN),
 * pero por el camino de la app: en vez de una sesión (cookie), el dispositivo canja el secreto por un
 * TOKEN y su operador vive en la fila del dispositivo. Estas pruebas vigilan lo delicado por HTTP:
 *
 *  - Un token válido CON operador pasa el gate `auth:sanctum` y opera con los permisos de su rol.
 *  - Un token SIN operador (en el bloqueo) no alcanza el POS: 401 —el token de dispositivo NO es un token
 *    Sanctum, así que por sí solo no autentica el POS—.
 *  - El token se entrega una sola vez; revocar lo deja fuera al instante.
 *  - Aislamiento de tenant, PIN homogéneo y bloqueo, inactividad, salir y atribución, igual que por cookie.
 */
beforeEach(function () {
    RateLimiter::clear('pin');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con app',
        ownerEmail: 'duena@app.mx',
        ownerFirstName: 'Rosa',
        ownerPaternalSurname: 'Lima',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->terminal = Terminal::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA1',
            'name' => 'Caja 1',
        ]);

        $rol = Role::create(['name' => 'Mesero con caja', 'guard_name' => 'web']);
        $rol->givePermissionTo(['pos.orders.create', 'pos.sessions.open']);
        $this->operatorRole = $rol;

        $this->operator = TenantMembership::factory()
            ->withoutCredentials()   // sin cuenta (D8): opera por su rol activo (D9), no por Auth::user()
            ->withPin('1234')
            ->allBranches()
            ->has(EmployeeProfile::factory(), 'employeeProfile')
            ->create([
                'employee_code' => 'MESERO1',
                'default_role_id' => $rol->id,
            ]);
    });
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Crea un dispositivo enrolado con un secreto CONOCIDO y marca la terminal como compartida; devuelve
 * `{ulid}|{secreto}`. Por modelo (no por el endpoint de enrolamiento) para no dejar un usuario pegado.
 */
function secretoParaToken(Terminal $terminal, string $plain = 'secreto-de-prueba-abcdef'): string
{
    return app(TenantContext::class)->runFor((int) $terminal->tenant_id, function () use ($terminal, $plain): string {
        $device = new TerminalDevice(['terminal_id' => $terminal->id, 'label' => 'Tablet caja']);
        $device->secret_hash = Hash::make($plain);
        $device->save();

        $terminal->update(['is_shared' => true]);

        return $device->ulid.'|'.$plain;
    });
}

/** Empareja el dispositivo por token (canjea el secreto) y devuelve el token en claro. */
function emparejarPorToken(): string
{
    $secret = secretoParaToken(test()->terminal);

    return test()->postJson('/api/v1/shared-terminal/token', ['secret' => $secret])
        ->assertOk()
        ->json('token');
}

/** Cliente de kiosco por TOKEN: manda el encabezado del token del dispositivo, sin sesión. */
function conToken(Tests\TestCase $test, string $token): Tests\TestCase
{
    return $test->withHeader('X-Terminal-Token', $token);
}

// -----------------------------------------------------------------------------------------------------
// Canje del secreto → token de dispositivo
// -----------------------------------------------------------------------------------------------------

it('canjea un secreto válido por un token de dispositivo', function () {
    $secret = secretoParaToken($this->terminal);

    $resp = $this->postJson('/api/v1/shared-terminal/token', ['secret' => $secret])
        ->assertOk()
        ->assertJsonPath('data.label', 'Tablet caja')
        ->assertJsonPath('branch.name', $this->branch->name);

    // El token viaja en claro UNA vez, y el hash NUNCA sale.
    expect($resp->json('token'))->toBeString()->not->toBeEmpty();
    expect(json_encode($resp->json()))->not->toContain('token_hash')
        ->and(json_encode($resp->json()))->not->toContain('secret_hash');
});

it('rechaza un secreto inválido con 401 al pedir token', function () {
    $this->postJson('/api/v1/shared-terminal/token', ['secret' => str_repeat('A', 26).'|noSirve'])
        ->assertUnauthorized();
});

it('un dispositivo revocado no puede canjear token', function () {
    $secret = secretoParaToken($this->terminal);

    app(TenantContext::class)->runFor($this->tenant->id, fn () => TerminalDevice::query()
        ->where('terminal_id', $this->terminal->id)
        ->update(['revoked_at' => now()]));

    $this->postJson('/api/v1/shared-terminal/token', ['secret' => $secret])
        ->assertUnauthorized();
});

// -----------------------------------------------------------------------------------------------------
// El gate: sin operador no hay POS; con operador, sí
// -----------------------------------------------------------------------------------------------------

it('un token SIN operador no alcanza el POS (401)', function () {
    $token = emparejarPorToken();

    // En el bloqueo: hay token de dispositivo pero no operador. El token NO es Sanctum, así que el gate
    // `auth:sanctum` no lo acepta por sí solo: 401.
    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

it('con operador identificado por token, opera el POS con los permisos de su rol', function () {
    $token = emparejarPorToken();

    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    // `pos.sessions.open` SÍ lo tiene su rol → pasa el gate y opera.
    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertOk();

    // `identity.users.view` NO lo tiene → 403 (autenticado como operador; falta el permiso), no 401.
    conToken($this, $token)->getJson('/api/v1/memberships')->assertForbidden();
});

// -----------------------------------------------------------------------------------------------------
// PIN, inactividad, salir
// -----------------------------------------------------------------------------------------------------

it('un PIN incorrecto y un código inexistente dan el MISMO error', function () {
    $token = emparejarPorToken();

    $codigoMalo = conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'NOEXISTE', 'pin' => '1234',
    ]);
    $pinMalo = conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '9999',
    ]);

    expect($codigoMalo->status())->toBe(422)
        ->and($pinMalo->status())->toBe(422)
        ->and($codigoMalo->json('title'))->toBe($pinMalo->json('title'));
});

it('bloquea el PIN tras agotar los intentos', function () {
    $token = emparejarPorToken();

    for ($i = 0; $i < 5; $i++) {
        conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
            'employee_code' => 'MESERO1', 'pin' => '9999',
        ])->assertStatus(422);
    }

    // Aun con el PIN correcto, bloqueado: 423.
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertStatus(423);
});

it('la inactividad devuelve la terminal al bloqueo', function () {
    $token = emparejarPorToken();
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertOk();

    // Pasado el umbral (90 s por omisión), la sesión de operación caduca.
    $this->travel(100)->seconds();

    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

it('salir por token devuelve la terminal al bloqueo', function () {
    $token = emparejarPorToken();
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertOk();

    conToken($this, $token)->deleteJson('/api/v1/shared-terminal/token/operator')->assertNoContent();

    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

it('los endpoints de operador por token exigen un token de dispositivo', function () {
    // Sin token no hay capa de dispositivo: identificar y salir responden 401, no 500.
    $this->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertUnauthorized();

    $this->deleteJson('/api/v1/shared-terminal/token/operator')->assertUnauthorized();

    // Un token inexistente tampoco vale.
    conToken($this, 'token-que-no-existe')->deleteJson('/api/v1/shared-terminal/token/operator')
        ->assertUnauthorized();
});

// -----------------------------------------------------------------------------------------------------
// Revocar mata el token
// -----------------------------------------------------------------------------------------------------

it('revocar el dispositivo deja el token fuera al instante', function () {
    $token = emparejarPorToken();
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();
    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertOk();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => TerminalDevice::query()
        ->where('terminal_id', $this->terminal->id)->first()?->revoke());

    // El token ya no resuelve: de vuelta al 401.
    conToken($this, $token)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

// -----------------------------------------------------------------------------------------------------
// Aislamiento de tenant y atribución
// -----------------------------------------------------------------------------------------------------

it('un empleado de otro negocio no se identifica en esta terminal por token', function () {
    $altaB = app(ProvisionTenant::class)->provision(
        businessName: 'Otra fonda', ownerEmail: 'duenob@otra.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Ruiz', plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($altaB['tenant']->id, function (): void {
        $rol = Role::create(['name' => 'Mesero B', 'guard_name' => 'web']);
        $rol->givePermissionTo('pos.orders.create');
        TenantMembership::factory()->withoutCredentials()->withPin('1234')->allBranches()->create([
            'employee_code' => 'SOLOB1', 'default_role_id' => $rol->id,
        ]);
    });

    $token = emparejarPorToken();

    // El código de B no existe en el tenant del dispositivo (A): mismo 422 indistinguible.
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'SOLOB1', 'pin' => '1234',
    ])->assertStatus(422);
});

it('lo que se hace bajo el operador por token queda a su nombre, sin usuario', function () {
    $token = emparejarPorToken();
    conToken($this, $token)->postJson('/api/v1/shared-terminal/token/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    conToken($this, $token)->postJson('/api/v1/pos-sessions', [
        'terminal_ulid' => $this->terminal->ulid,
        'opening_float' => '0.00',
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $entry = AuditEntry::query()
        ->where('action', AuditAction::CASH_SESSION_OPENED)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_membership_id)->toBe($this->operator->id)
        ->and($entry->actor_user_id)->toBeNull();
});

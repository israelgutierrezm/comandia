<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TERMINAL COMPARTIDA OPERADA POR PIN (ADR-012), FASE (a) — BACKEND WEB POS
 *
 * La estación fija por la que rotan meseros: un DISPOSITIVO enrolado (credencial sin usuario) sobre el
 * que cada operador teclea su código de empleado + PIN para abrir una sesión de operación corta. El
 * operador puede ser personal SIN cuenta (D8): opera por su rol activo (D9), no por `Auth::user()`.
 *
 * Lo que estas pruebas vigilan es lo delicado del ADR, ejercitado por HTTP de punta a punta:
 *
 *  - Un dispositivo CON operador pasa el gate `auth:sanctum` y opera con los permisos de SU rol.
 *  - Un dispositivo SIN operador (en el bloqueo) no alcanza el POS: 401, hay que teclear el PIN.
 *  - El secreto del dispositivo se entrega una sola vez y jamás vuelve; enrolar exige permiso.
 *  - Aislamiento de tenant: un empleado de otro negocio no se identifica en esta terminal.
 *  - PIN: inválido da error homogéneo y bloquea tras N; la inactividad devuelve al bloqueo.
 *  - Atribución: lo que se hace bajo el operador queda a su nombre (membresía, sin usuario).
 */
beforeEach(function () {
    // El límite de intentos es real y se comparte en el proceso: sin limpiarlo, una prueba que agota el
    // PIN heredaría los intentos de la anterior y el resultado dependería del orden.
    RateLimiter::clear('pin');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda compartida',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Rosa',
        ownerPaternalSurname: 'Lima',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->terminal = Terminal::factory()->create([
            'branch_id' => $this->branch->id,
            'code' => 'CAJA1',
            'name' => 'Caja 1',
        ]);

        // El rol del OPERADOR. Sus permisos salen de aquí (rol activo, D9), no del usuario — que además
        // no existe: el operador es personal sin cuenta (D8).
        $rol = Role::create(['name' => 'Mesero con caja', 'guard_name' => 'web']);
        $rol->givePermissionTo(['pos.orders.create', 'pos.sessions.open']);
        $this->operatorRole = $rol;

        $this->operator = TenantMembership::factory()
            ->withoutCredentials()   // user_id NULL: el empleado sin cuenta que el ADR existe para habilitar.
            ->withPin('1234')
            ->allBranches()
            // Sin cuenta, el nombre sale del perfil de empleado (invariante I1 / D66): toda membresía sin
            // credenciales lo tiene, y sin él la serialización de sus acciones no tendría a quién nombrar.
            ->has(EmployeeProfile::factory(), 'employeeProfile')
            ->create([
                'employee_code' => 'MESERO1',
                'default_role_id' => $rol->id,
            ]);
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Crea un dispositivo enrolado con un secreto CONOCIDO y marca la terminal como compartida.
 *
 * Se hace por modelo, y no por el endpoint de enrolamiento, a propósito: `actingAs()` del cliente de
 * pruebas deja el usuario pegado para el resto de la prueba, y el flujo de dispositivo tiene que correr
 * SIN usuario. Enrolar por HTTP se prueba en su propio bloque, donde no le sigue un flujo de dispositivo.
 */
function secretoDeDispositivo(Terminal $terminal, string $plain = 'secreto-de-prueba-abcdef'): string
{
    return app(TenantContext::class)->runFor((int) $terminal->tenant_id, function () use ($terminal, $plain): string {
        $device = new TerminalDevice(['terminal_id' => $terminal->id, 'label' => 'Caja mostrador']);
        $device->secret_hash = Hash::make($plain);
        $device->save();

        $terminal->update(['is_shared' => true]);

        return $device->ulid.'|'.$plain;
    });
}

/** Cliente de DISPOSITIVO: sin usuario, con `Referer` para que la petición sea con estado (sesión). */
function comoDispositivo(Tests\TestCase $test): Tests\TestCase
{
    return $test->withHeader('Referer', (string) config('app.url'));
}

// -----------------------------------------------------------------------------------------------------
// Canje del secreto → sesión de dispositivo
// -----------------------------------------------------------------------------------------------------

it('canjea un secreto válido por una sesión de dispositivo', function () {
    $secret = secretoDeDispositivo($this->terminal);

    comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])
        ->assertOk()
        ->assertJsonPath('data.label', 'Caja mostrador')
        ->assertJsonPath('branch.name', $this->branch->name);
});

it('rechaza un secreto inválido con el mismo 401, sin decir por qué', function () {
    // Un secreto con forma correcta pero que no corresponde a ninguna fila.
    $inexistente = comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => str_repeat('A', 26).'|noSirve'])
        ->assertUnauthorized();

    // Uno con el ulid real pero el secreto equivocado.
    $secret = secretoDeDispositivo($this->terminal);
    [$ulid] = explode('|', $secret, 2);

    $malSecreto = comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => $ulid.'|equivocado'])
        ->assertUnauthorized();

    // Indistinguibles: si difirieran, el endpoint sería un oráculo de ulids válidos.
    expect($inexistente->status())->toBe($malSecreto->status());
});

it('un dispositivo revocado no puede canjear sesión', function () {
    $secret = secretoDeDispositivo($this->terminal);

    app(TenantContext::class)->runFor($this->tenant->id, fn () => TerminalDevice::query()
        ->where('terminal_id', $this->terminal->id)
        ->update(['revoked_at' => now()]));

    comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])
        ->assertUnauthorized();
});

// -----------------------------------------------------------------------------------------------------
// El gate: sin operador no hay POS; con operador, sí
// -----------------------------------------------------------------------------------------------------

it('un dispositivo SIN operador no alcanza el POS (401)', function () {
    $secret = secretoDeDispositivo($this->terminal);

    comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])
        ->assertOk();

    // En el bloqueo: hay dispositivo pero no operador. El gate `auth:sanctum` protege el POS.
    comoDispositivo($this)
        ->getJson('/api/v1/pos-sessions')
        ->assertUnauthorized();
});

it('con operador identificado, opera el POS con los permisos de su rol', function () {
    $secret = secretoDeDispositivo($this->terminal);

    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    // `pos.sessions.open` SÍ lo tiene su rol → pasa el gate y opera.
    comoDispositivo($this)->getJson('/api/v1/pos-sessions')->assertOk();

    // `identity.memberships.view` NO lo tiene → el secreto de dispositivo no alcanza administración: 403,
    // no 401. Está autenticado como operador; lo que falta es el permiso.
    comoDispositivo($this)->getJson('/api/v1/memberships')->assertForbidden();
});

// -----------------------------------------------------------------------------------------------------
// PIN: identificación, error homogéneo, bloqueo
// -----------------------------------------------------------------------------------------------------

it('un PIN incorrecto y un código inexistente dan el MISMO error', function () {
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();

    $codigoMalo = comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'NOEXISTE', 'pin' => '1234',
    ]);

    $pinMalo = comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '9999',
    ]);

    expect($codigoMalo->status())->toBe(422)
        ->and($pinMalo->status())->toBe(422)
        ->and($codigoMalo->json('title'))->toBe($pinMalo->json('title'));
});

it('bloquea el PIN tras agotar los intentos', function () {
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();

    // Cinco intentos fallidos (el máximo por omisión): el quinto fija el bloqueo.
    for ($i = 0; $i < 5; $i++) {
        comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
            'employee_code' => 'MESERO1', 'pin' => '9999',
        ])->assertStatus(422);
    }

    // Ahora, aun con el PIN CORRECTO, está bloqueado: 423.
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertStatus(423);
});

it('el operador no puede identificarse si no opera en la sucursal de la terminal', function () {
    // Un operador con PIN correcto pero SIN alcance en ninguna sucursal: el PIN no falla, pero no puede
    // tomar esta caja. Da el mismo 422 indistinguible (no filtra que el PIN era correcto).
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        TenantMembership::factory()->withoutCredentials()->withPin('1234')->create([
            'employee_code' => 'SINSUCURSAL',
            'default_role_id' => $this->operatorRole->id,
            'has_all_branches' => false,
        ]);
    });

    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();

    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'SINSUCURSAL', 'pin' => '1234',
    ])->assertStatus(422);
});

// -----------------------------------------------------------------------------------------------------
// Inactividad → vuelve al bloqueo
// -----------------------------------------------------------------------------------------------------

it('la inactividad devuelve la terminal al bloqueo', function () {
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    // Opera ahora.
    comoDispositivo($this)->getJson('/api/v1/pos-sessions')->assertOk();

    // Pasado el umbral (90 s por omisión), la sesión de operación caduca: el operador se olvida y el POS
    // vuelve a exigir el PIN.
    $this->travel(100)->seconds();

    comoDispositivo($this)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

it('salir devuelve la terminal al bloqueo', function () {
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    comoDispositivo($this)->getJson('/api/v1/pos-sessions')->assertOk();

    comoDispositivo($this)->deleteJson('/api/v1/shared-terminal/operator')->assertNoContent();

    // Ya sin operador: de vuelta al bloqueo.
    comoDispositivo($this)->getJson('/api/v1/pos-sessions')->assertUnauthorized();
});

it('los endpoints de operador exigen sesión de dispositivo', function () {
    // Sin canjear el secreto no hay capa de dispositivo: identificar y salir responden 401, no 500.
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertUnauthorized();

    comoDispositivo($this)->deleteJson('/api/v1/shared-terminal/operator')->assertUnauthorized();
});

// -----------------------------------------------------------------------------------------------------
// Aislamiento de tenant
// -----------------------------------------------------------------------------------------------------

it('un empleado de otro negocio no se identifica en esta terminal', function () {
    // Tenant B con un empleado de código ÚNICO que no existe en A.
    $altaB = app(ProvisionTenant::class)->provision(
        businessName: 'Otra fonda',
        ownerEmail: 'duenob@otra.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($altaB['tenant']->id, function (): void {
        $rol = Role::create(['name' => 'Mesero B', 'guard_name' => 'web']);
        $rol->givePermissionTo('pos.orders.create');

        TenantMembership::factory()->withoutCredentials()->withPin('1234')->allBranches()->create([
            'employee_code' => 'SOLOB1',
            'default_role_id' => $rol->id,
        ]);
    });

    // Sobre el dispositivo de A, el código de B no existe: la búsqueda del operador está acotada al tenant
    // del dispositivo (ADR-002). Mismo 422 indistinguible que un código inexistente.
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();

    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'SOLOB1', 'pin' => '1234',
    ])->assertStatus(422);
});

// -----------------------------------------------------------------------------------------------------
// Atribución
// -----------------------------------------------------------------------------------------------------

it('lo que se hace bajo el operador queda a su nombre, sin usuario', function () {
    $secret = secretoDeDispositivo($this->terminal);
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])->assertOk();
    comoDispositivo($this)->postJson('/api/v1/shared-terminal/operator', [
        'employee_code' => 'MESERO1', 'pin' => '1234',
    ])->assertNoContent();

    // Una escritura real del POS: abrir la caja.
    comoDispositivo($this)->postJson('/api/v1/pos-sessions', [
        'terminal_ulid' => $this->terminal->ulid,
        'opening_float' => '0.00',
    ])->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    $entry = AuditEntry::query()
        ->where('action', AuditAction::CASH_SESSION_OPENED)
        ->latest('id')
        ->first();

    // A nombre de la MEMBRESÍA del operador, y con usuario NULO: es personal sin cuenta, y aun así la
    // acción queda atribuida a una persona concreta.
    expect($entry)->not->toBeNull()
        ->and($entry->actor_membership_id)->toBe($this->operator->id)
        ->and($entry->actor_user_id)->toBeNull();
});

// -----------------------------------------------------------------------------------------------------
// Enrolamiento (superficie de administración, con usuario y permiso)
// -----------------------------------------------------------------------------------------------------

it('enrolar entrega el secreto una sola vez y marca la terminal como compartida', function () {
    expect($this->terminal->fresh()->is_shared)->toBeFalse();

    $resp = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/terminals/{$this->terminal->ulid}/enroll", ['label' => 'Tablet terraza'])
        ->assertCreated();

    // El secreto viene como `{ulid}|{secreto}`: 26 + 1 + 40.
    $secret = $resp->json('secret');
    [$ulid, $plain] = explode('|', $secret, 2);

    expect(strlen($ulid))->toBe(26)
        ->and(strlen($plain))->toBe(40);

    // El hash NUNCA sale en la respuesta.
    expect(json_encode($resp->json()))->not->toContain('secret_hash');

    // La terminal quedó compartida y el dispositivo existe.
    app(TenantContext::class)->set($this->tenant->id);
    expect($this->terminal->refresh()->is_shared)->toBeTrue()
        ->and(TerminalDevice::query()->where('terminal_id', $this->terminal->id)->exists())->toBeTrue();
});

it('el secreto entregado sirve para canjear una sesión de dispositivo', function () {
    $secret = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/terminals/{$this->terminal->ulid}/enroll", ['label' => 'Tablet terraza'])
        ->assertCreated()
        ->json('secret');

    // Extremo a extremo: el secreto que entrega el enrolamiento es el que abre la sesión de dispositivo.
    comoDispositivo($this)
        ->postJson('/api/v1/shared-terminal/session', ['secret' => $secret])
        ->assertOk();
});

it('enrolar exige el permiso: sin él, 403', function () {
    $empleado = User::factory()->create();

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($empleado): void {
        $rol = Role::create(['name' => 'Sólo ve terminales', 'guard_name' => 'web']);
        $rol->givePermissionTo('organization.terminals.view');
        $empleado->assignRole($rol);

        TenantMembership::factory()->allBranches()->create([
            'user_id' => $empleado->id,
            'default_role_id' => $rol->id,
        ]);
    });

    $this->actingAsSpa($empleado, $this->tenant->id)
        ->postJson("/api/v1/terminals/{$this->terminal->ulid}/enroll", ['label' => 'X'])
        ->assertForbidden();
});

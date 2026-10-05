<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\IssueApiToken;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * SALIR DE VERDAD Y MIS DISPOSITIVOS (diseño de acceso, fase 1)
 *
 * ## Lo que estas pruebas existen para demostrar
 *
 * **Salir en la app revoca el token.** Antes la app lo borraba del teléfono y en el servidor seguía valiendo para
 * siempre: quien lo hubiera copiado seguía entrando.
 *
 * **Las sesiones son de la persona, y cada negocio sólo ve las suyas.** Una persona con dos negocios ve y cierra sus
 * sesiones de los dos; un administrador ve y cierra sólo las de su negocio, y el asiento cae en la bitácora del negocio
 * de la sesión, firmado por quien la cerró.
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

    // Otro negocio, de otra dueña, donde Ana TAMBIÉN trabaja: la misma cuenta, dos membresías.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'secreto-largo-456',
    );

    $this->otroTenant = $otro['tenant'];

    $this->anaEnElOtro = app(TenantContext::class)->runFor($this->otroTenant->id, fn (): TenantMembership => TenantMembership::factory()->create([
        'user_id' => $this->owner->id,
        'employee_code' => 'A900',
        'status' => 'active',
        'has_all_branches' => true,
    ]));

    // Luz, mesera de la fonda, con su propia cuenta.
    $this->luz = User::factory()->create(['email' => 'luz@fonda.mx', 'password' => 'contrasena-larga-1']);

    $this->luzMembership = app(TenantContext::class)->runFor($this->tenant->id, function (): TenantMembership {
        $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();

        $membresia = TenantMembership::factory()->create([
            'user_id' => $this->luz->id,
            'employee_code' => 'M001',
            'status' => 'active',
            'has_all_branches' => true,
            'default_role_id' => $mesero->id,
        ]);

        $this->luz->syncRoles([$mesero]);

        return $membresia;
    });

    /** Emite un token de la app para una membresía y devuelve su texto. */
    $this->tokenPara = fn (TenantMembership $membresia, string $dispositivo): string => app(TenantContext::class)->runFor(
        (int) $membresia->tenant_id,
        fn (): string => app(IssueApiToken::class)->issue($membresia->refresh(), $dispositivo)->plainTextToken,
    );

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Los asientos de una acción en un negocio. */
function asientosDe(int $tenantId, string $accion): \Illuminate\Support\Collection
{
    return app(TenantContext::class)->runFor(
        $tenantId,
        fn () => AuditEntry::query()->where('action', $accion)->orderBy('id')->get(),
    );
}

// ---------------------------------------------------------------------------
// Salir en la app
// ---------------------------------------------------------------------------

it('salir en la app revoca el token en el servidor', function () {
    $token = $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'Motorola moto g32 · Android 14',
        'tenant_ulid' => $this->tenant->ulid,
    ])->assertCreated()->json('token');

    $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(PersonalAccessToken::query()->count())->toBe(0);

    // La siguiente petición con ese token ya no entra. Se olvida el guardia porque, dentro de una misma prueba, Laravel
    // reutiliza el usuario ya resuelto de la petición anterior.
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v1/context')->assertUnauthorized();

    $asiento = asientosDe($this->tenant->id, AuditAction::APP_SESSION_REVOKED)->sole();
    expect($asiento->after)->toBe(['how' => 'logout']);
    expect($asiento->before['device'])->toBe('Motorola moto g32 · Android 14');
    expect((int) $asiento->actor_membership_id)->toBe($this->ownerMembership->id);
});

it('desde un navegador no hay token que revocar: se sale con «Salir»', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson('/api/v1/auth/token')
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// Mis dispositivos
// ---------------------------------------------------------------------------

it('mis dispositivos lista mis sesiones de la app en mis dos negocios, y ninguna ajena', function () {
    ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');
    ($this->tokenPara)($this->luzMembership, 'Teléfono de Luz');

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/me/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    expect(collect($datos)->pluck('device_name')->sort()->values()->all())->toBe(['iPad del café', 'iPhone de Ana']);
    expect(collect($datos)->pluck('business.name')->sort()->values()->all())->toBe(['Café del Norte', 'Fonda del Centro']);

    // Nunca sale el token ni su id secuencial: sólo el ULID con el que se cierra.
    expect(array_keys($datos[0]))->toBe(['ulid', 'device_name', 'business', 'created_at', 'last_used_at', 'closes_idle_at', 'is_current']);

    // Desde el navegador ninguna es «ésta»; desde la app, la suya sí.
    expect(collect($datos)->pluck('is_current')->unique()->all())->toBe([false]);

    $token = ($this->tokenPara)($this->ownerMembership, 'Android de Ana');

    Auth::forgetGuards();

    $actual = collect($this->withToken($token)->getJson('/api/v1/me/sessions')->assertOk()->json('data'))
        ->firstWhere('is_current', true);

    expect($actual['device_name'])->toBe('Android de Ana');
});

it('cierro una sesión mía de OTRO negocio y el asiento cae en la bitácora de ese negocio, a mi nombre', function () {
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');

    $ulid = app(TenantContext::class)->runFor($this->otroTenant->id, fn () => PersonalAccessToken::query()->sole()->ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/me/sessions/{$ulid}")
        ->assertNoContent();

    expect(PersonalAccessToken::query()->count())->toBe(0);

    // En el café, firmado por la membresía de Ana EN EL CAFÉ —no por la de la fonda, que es de otro negocio—.
    $asiento = asientosDe($this->otroTenant->id, AuditAction::APP_SESSION_REVOKED)->sole();
    expect((int) $asiento->actor_membership_id)->toBe($this->anaEnElOtro->id);
    expect($asiento->after)->toBe(['how' => 'self']);

    // Y la fonda no se entera de nada del café.
    expect(asientosDe($this->tenant->id, AuditAction::APP_SESSION_REVOKED))->toHaveCount(0);
});

it('no cierro la sesión de otra persona: responde como si no existiera', function () {
    ($this->tokenPara)($this->luzMembership, 'Teléfono de Luz');

    $ulid = PersonalAccessToken::query()->sole()->ulid;

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/me/sessions/{$ulid}")
        ->assertNotFound();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

it('cerrar todas mis sesiones de la app cierra las de mis dos negocios y ninguna ajena', function () {
    ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');
    ($this->tokenPara)($this->luzMembership, 'Teléfono de Luz');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson('/api/v1/me/sessions')
        ->assertOk()
        ->assertJsonPath('data.closed', 2);

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['Teléfono de Luz']);
});

// ---------------------------------------------------------------------------
// Mis otras sesiones web
// ---------------------------------------------------------------------------

it('cerrar mis otras sesiones web pide mi contraseña, saca a las demás y conserva ésta', function () {
    $hashAntes = (string) $this->owner->password;

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/me/sessions/close-other-web', ['password' => 'no-es-la-mia'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'La contraseña no es correcta.');

    expect((string) $this->owner->refresh()->password)->toBe($hashAntes);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/me/sessions/close-other-web', ['password' => 'secreto-largo-123'])
        ->assertNoContent();

    // La contraseña es la misma, con otro cifrado: eso es lo que invalida a las demás sesiones.
    $this->owner->refresh();
    expect((string) $this->owner->password)->not->toBe($hashAntes);
    expect(Hash::check('secreto-largo-123', (string) $this->owner->password))->toBeTrue();

    // Otra sesión, que guardó el cifrado anterior, ya no entra: ni a la API…
    //
    // Con el guardia `web` explícito: dentro de una prueba, la llamada anterior a la API deja a Sanctum como guardia por
    // omisión, y un navegador real llega con la sesión del guardia web.
    $otraSesion = fn () => $this->flushSession()
        ->withHeader('Referer', (string) config('app.url'))
        ->withSession(['tenant_id' => $this->tenant->id, 'password_hash_web' => $hashAntes])
        ->actingAs($this->owner, 'web');

    $otraSesion()->getJson('/api/v1/context')->assertUnauthorized();

    // …ni a las pantallas, que antes no lo revisaban.
    Auth::forgetGuards();
    $otraSesion()->withoutVite()->get('/admin')->assertRedirect('/login');

    expect(asientosDe($this->tenant->id, AuditAction::OTHER_WEB_SESSIONS_CLOSED))->toHaveCount(1);
});

it('desde la app no hay sesiones web que cerrar', function () {
    $token = ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');

    $this->withToken($token)
        ->postJson('/api/v1/me/sessions/close-other-web', ['password' => 'secreto-largo-123'])
        ->assertStatus(409);
});

// ---------------------------------------------------------------------------
// La ficha de una persona (administración)
// ---------------------------------------------------------------------------

it('un administrador ve y cierra las sesiones de la app de una persona, sólo de su negocio', function () {
    ($this->tokenPara)($this->luzMembership, 'Teléfono de Luz');

    // Luz también trabaja en el café, con otra sesión: la fonda no la ve ni la cierra.
    $luzEnElCafe = app(TenantContext::class)->runFor($this->otroTenant->id, fn (): TenantMembership => TenantMembership::factory()->create([
        'user_id' => $this->luz->id,
        'employee_code' => 'L900',
        'status' => 'active',
        'has_all_branches' => true,
    ]));

    ($this->tokenPara)($luzEnElCafe, 'Tablet del café');

    $sesiones = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/memberships/{$this->luzMembership->ulid}/app-sessions")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->json('data');

    expect($sesiones[0]['device_name'])->toBe('Teléfono de Luz');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/memberships/{$this->luzMembership->ulid}/app-sessions/{$sesiones[0]['ulid']}")
        ->assertNoContent();

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['Tablet del café']);

    // La del café no se cierra desde la fonda aunque se conozca su ULID.
    $delCafe = PersonalAccessToken::query()->sole()->ulid;

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/memberships/{$this->luzMembership->ulid}/app-sessions/{$delCafe}")
        ->assertNotFound();

    // El asiento, en la fonda y firmado por quien la cerró: la dueña, no Luz.
    $asiento = asientosDe($this->tenant->id, AuditAction::APP_SESSION_REVOKED)->sole();
    expect((int) $asiento->actor_membership_id)->toBe($this->ownerMembership->id);
    expect($asiento->after)->toBe(['how' => 'admin']);
});

it('sin el permiso de suspender no se ven ni se cierran las sesiones de otra persona', function () {
    $token = ($this->tokenPara)($this->luzMembership, 'Teléfono de Luz');
    $ulid = PersonalAccessToken::query()->sole()->ulid;

    // Luz, mesera, sobre la ficha de la dueña y sobre la suya.
    $this->withToken($token)
        ->getJson("/api/v1/memberships/{$this->ownerMembership->ulid}/app-sessions")
        ->assertForbidden();

    $this->withToken($token)
        ->deleteJson("/api/v1/memberships/{$this->luzMembership->ulid}/app-sessions/{$ulid}")
        ->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Caducidad por falta de uso
// ---------------------------------------------------------------------------

it('una sesión de la app que nadie usa en 60 días se cierra sola, a nombre del sistema', function () {
    $ahora = CarbonImmutable::now();

    foreach ([
        'Usada hace 61 días' => ['last_used_at' => $ahora->subDays(61), 'created_at' => $ahora->subDays(200)],
        'Nunca usada, de hace 61 días' => ['last_used_at' => null, 'created_at' => $ahora->subDays(61)],
        'Usada hace 10 días' => ['last_used_at' => $ahora->subDays(10), 'created_at' => $ahora->subDays(200)],
        'Nunca usada, de ayer' => ['last_used_at' => null, 'created_at' => $ahora->subDay()],
    ] as $dispositivo => $fechas) {
        ($this->tokenPara)($this->luzMembership, $dispositivo);

        PersonalAccessToken::query()->where('name', $dispositivo)->update($fechas);
    }

    $this->artisan('comandia:app-sessions:prune')->assertSuccessful();

    expect(PersonalAccessToken::query()->orderBy('name')->pluck('name')->all())
        ->toBe(['Nunca usada, de ayer', 'Usada hace 10 días']);

    $asientos = asientosDe($this->tenant->id, AuditAction::APP_SESSION_REVOKED);

    expect($asientos)->toHaveCount(2);
    expect($asientos->pluck('after')->unique()->values()->all())->toBe([['how' => 'idle']]);
    expect($asientos->pluck('actor_membership_id')->unique()->values()->all())->toBe([null]);
});

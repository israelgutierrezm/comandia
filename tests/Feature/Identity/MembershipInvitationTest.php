<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\MembershipInvitations;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Mail\MembershipInvitationMail;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use App\Modules\Tenancy\Infrastructure\Models\TenantLimit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DAR ACCESO CON UNA INVITACIÓN (diseño de acceso, fase 3)
 *
 * ## Lo que estas pruebas existen para demostrar
 *
 * **Que nadie conoce la contraseña de otro.** La persona la crea al aceptar, o acepta con la suya si ya usa Comandia en
 * otro negocio; el negocio sólo manda el enlace.
 *
 * **Que nadie queda en un negocio sin haberlo aceptado**, ni con la cuenta de otra persona: aceptar exige ser el
 * destinatario —con su contraseña, o con su sesión abierta—, y el enlace sólo sirve una vez y por siete días.
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

    $this->mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail(),
    );

    // Lupe, en nómina: tiene perfil y código, pero no cuenta.
    $this->lupe = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', [
            'first_name' => 'Lupe',
            'paternal_surname' => 'Ríos',
            'employee_code' => 'L001',
            'employee_profile' => ['legal_first_name' => 'Guadalupe', 'legal_paternal_surname' => 'Ríos'],
        ])
        ->assertCreated()
        ->json('data.ulid');

    /** Invita y devuelve el enlace en claro. */
    $this->invitar = fn (string $membresia, string $correo, ?array $roles = null) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$membresia}/invitation", array_filter([
            'email' => $correo,
            'role_ulids' => $roles,
        ], fn ($valor) => $valor !== null));

    /** El token del enlace. */
    $this->tokenDe = fn (string $enlace): string => basename(parse_url($enlace, PHP_URL_PATH));

    /**
     * El navegador de quien abre el enlace. Dentro de una prueba la aplicación es la misma entre peticiones, y la sesión
     * de la dueña que invitó —y el guardia que dejó la API— seguirían puestos: aquí se empieza de cero, como en un
     * navegador ajeno.
     */
    $this->comoVisitante = function () {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushSession();
        $this->flushHeaders();

        // Y sin el contexto de la petición anterior: en producción cada petición nace con él vacío.
        app(ContextHolder::class)->forget();
        app(TenantContext::class)->forget();

        return $this;
    };

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function membresiaDeLupe(): TenantMembership
{
    return app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn (): TenantMembership => TenantMembership::query()->with('user')->where('ulid', test()->lupe)->sole(),
    );
}

// ---------------------------------------------------------------------------
// Invitar, reenviar, cancelar
// ---------------------------------------------------------------------------

it('«Dar acceso» a alguien de nómina le manda su invitación y no toca su cuenta ni su PIN', function () {
    Mail::fake();

    $respuesta = ($this->invitar)($this->lupe, 'lupe@fonda.mx', [$this->mesero->ulid])
        ->assertCreated()
        ->assertJsonPath('data.invitation.email', 'lupe@fonda.mx')
        ->assertJsonPath('data.has_credentials', false)
        // Sigue activa: en nómina opera con su PIN mientras no acepte.
        ->assertJsonPath('data.status', 'active');

    $enlace = $respuesta->json('meta.invitation_link');

    expect($enlace)->toStartWith(rtrim((string) config('app.url'), '/').'/invitacion/');

    Mail::assertSent(MembershipInvitationMail::class, fn (MembershipInvitationMail $correo): bool => $correo->hasTo('lupe@fonda.mx')
        && $correo->link === $enlace
        && $correo->businessName === 'Fonda del Centro'
        && $correo->roles === [RoleTemplates::WAITER]);

    // En la base sólo el hash: una copia de la base no entrega invitaciones utilizables.
    $guardada = app(TenantContext::class)->runFor($this->tenant->id, fn () => MembershipInvitation::query()->sole());
    expect($guardada->token_hash)->toBe(hash('sha256', ($this->tokenDe)($enlace)));

    // Y el enlace no vuelve a salir: la ficha dice que hay invitación, no cuál es.
    $ficha = $this->actingAsSpa($this->owner, $this->tenant->id)->getJson("/api/v1/memberships/{$this->lupe}")->json();
    expect(json_encode($ficha))->not->toContain(($this->tokenDe)($enlace));

    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => AuditEntry::query()->where('action', AuditAction::INVITATION_SENT)->count(),
    ))->toBe(1);
});

it('reenviar deja una sola invitación vigente, con los roles de la anterior', function () {
    $primero = ($this->invitar)($this->lupe, 'lupe@fonda.mx', [$this->mesero->ulid])->assertCreated()->json('meta.invitation_link');
    $segundo = ($this->invitar)($this->lupe, 'lupe@fonda.mx')->assertCreated()->json('meta.invitation_link');

    expect($segundo)->not->toBe($primero);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(MembershipInvitation::query()->open()->count())->toBe(1);
        expect(MembershipInvitation::query()->whereNotNull('revoked_at')->count())->toBe(1);
        expect(MembershipInvitation::query()->open()->sole()->roles()->pluck('name')->all())->toBe([RoleTemplates::WAITER]);
    });

    // El primer enlace ya no sirve.
    ($this->comoVisitante)()->withoutVite()->get('/invitacion/'.($this->tokenDe)($primero))
        ->assertOk()
        ->assertViewHas('page', fn (array $pagina): bool => $pagina['props']['state'] === 'revoked');
});

it('cancelar la invitación la deja sin efecto, y cancelar lo que no hay responde 404', function () {
    $enlace = ($this->invitar)($this->lupe, 'lupe@fonda.mx')->assertCreated()->json('meta.invitation_link');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/memberships/{$this->lupe}/invitation")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/memberships/{$this->lupe}/invitation")
        ->assertNotFound();

    ($this->comoVisitante)()->post('/invitacion/'.($this->tokenDe)($enlace), [
        'first_name' => 'Lupe', 'paternal_surname' => 'Ríos',
        'password' => 'mi-clave-larga', 'password_confirmation' => 'mi-clave-larga',
    ])->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'lupe@fonda.mx')->exists())->toBeFalse();
});

it('no se invita a quien ya entra, a quien está suspendido ni con el correo de alguien del negocio', function () {
    // La dueña ya entra con su cuenta.
    ($this->invitar)($this->ownerMembership->ulid, 'otra@fonda.mx')->assertStatus(409);

    // Otra persona del negocio ya entra con ese correo.
    ($this->invitar)($this->lupe, 'ana@fonda.mx')->assertStatus(409);

    // Suspendida.
    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/memberships/{$this->lupe}/suspend")->assertOk();
    ($this->invitar)($this->lupe, 'lupe@fonda.mx')->assertStatus(409);
});

it('sin el permiso de dar de alta no se invita ni se cancela', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->owner->assignRole($this->mesero));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $this->mesero->ulid)
        ->postJson("/api/v1/memberships/{$this->lupe}/invitation", ['email' => 'lupe@fonda.mx'])
        ->assertForbidden();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $this->mesero->ulid)
        ->deleteJson("/api/v1/memberships/{$this->lupe}/invitation")
        ->assertForbidden();
});

it('una invitada se activa al aceptar, no porque alguien la reactive; y suspenderla cancela su invitación', function () {
    $invitada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', ['email' => 'luis@fonda.mx', 'first_name' => 'Luis', 'paternal_surname' => 'Pérez'])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/memberships/{$invitada}/reactivate")
        ->assertStatus(409);

    $this->actingAsSpa($this->owner, $this->tenant->id)->postJson("/api/v1/memberships/{$invitada}/suspend")->assertOk();

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => MembershipInvitation::query()->open()->count()))->toBe(0);
});

// ---------------------------------------------------------------------------
// Aceptar
// ---------------------------------------------------------------------------

it('quien no tiene cuenta la crea al aceptar y entra al negocio con los roles de la invitación', function () {
    $enlace = ($this->invitar)($this->lupe, 'lupe@fonda.mx', [$this->mesero->ulid])->json('meta.invitation_link');
    $token = ($this->tokenDe)($enlace);

    $pagina = ($this->comoVisitante)()->withoutVite()->get("/invitacion/{$token}")->assertOk()->viewData('page')['props'];

    expect($pagina['state'])->toBe('pending');
    expect($pagina['mode'])->toBe('new_account');
    expect($pagina['business'])->toBe('Fonda del Centro');
    expect($pagina['roles'])->toBe([RoleTemplates::WAITER]);
    expect($pagina['suggested_name']['first_name'])->toBe('Guadalupe');

    $this->post("/invitacion/{$token}", [
        'first_name' => 'Lupe',
        'paternal_surname' => 'Ríos',
        'password' => 'mi-clave-larga',
        'password_confirmation' => 'mi-clave-larga',
    ])->assertRedirect(route('admin.dashboard'));

    $cuenta = User::query()->where('email', 'lupe@fonda.mx')->sole();

    expect(Hash::check('mi-clave-larga', (string) $cuenta->password))->toBeTrue();
    expect($cuenta->email_verified_at)->not->toBeNull();
    expect(Auth::guard('web')->id())->toBe($cuenta->id);
    expect(session('tenant_id'))->toBe($this->tenant->id);

    $membresia = membresiaDeLupe();
    expect((int) $membresia->user_id)->toBe($cuenta->id);
    expect($membresia->status->value)->toBe('active');

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($cuenta): void {
        expect($cuenta->fresh()->roles()->pluck('name')->all())->toBe([RoleTemplates::WAITER]);
        expect(MembershipInvitation::query()->sole()->accepted_at)->not->toBeNull();
        expect(AuditEntry::query()->where('action', AuditAction::INVITATION_ACCEPTED)->count())->toBe(1);
    });

    // El enlace se gasta: aceptar otra vez no liga ni activa nada más.
    Auth::guard('web')->logout();

    $this->post("/invitacion/{$token}", [
        'first_name' => 'Lupe', 'paternal_surname' => 'Ríos',
        'password' => 'otra-clave-larga', 'password_confirmation' => 'otra-clave-larga',
    ])->assertSessionHasErrors('invitation');
});

it('quien ya usa Comandia acepta con su contraseña de siempre, y con otra no', function () {
    // Beto es dueño de otro negocio: ya tiene cuenta.
    app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-de-beto',
    );
    app(TenantContext::class)->forget();

    $token = ($this->tokenDe)(($this->invitar)($this->lupe, 'beto@cafe.mx')->json('meta.invitation_link'));

    expect(($this->comoVisitante)()->withoutVite()->get("/invitacion/{$token}")->viewData('page')['props']['mode'])->toBe('existing_account');

    $this->post("/invitacion/{$token}", ['password' => 'no-es-la-suya'])->assertSessionHasErrors('password');

    expect(membresiaDeLupe()->user_id)->toBeNull();

    $this->post("/invitacion/{$token}", ['password' => 'secreto-de-beto'])->assertRedirect(route('admin.dashboard'));

    $beto = User::query()->where('email', 'beto@cafe.mx')->sole();

    expect((int) membresiaDeLupe()->user_id)->toBe($beto->id);
    expect($beto->membershipsAcrossTenants()->count())->toBe(2);

    // Su contraseña no cambió: aceptar no la toca.
    expect(Hash::check('secreto-de-beto', (string) $beto->fresh()->password))->toBeTrue();
});

it('con la sesión de otra persona no se acepta: hay que salir primero', function () {
    $token = ($this->tokenDe)(($this->invitar)($this->lupe, 'lupe@fonda.mx')->json('meta.invitation_link'));

    // Ana tiene la sesión abierta en el navegador donde se abre el enlace de Lupe.
    ($this->comoVisitante)()->actingAs($this->owner, 'web');

    expect($this->withoutVite()->get("/invitacion/{$token}")->viewData('page')['props']['mode'])->toBe('other_session');

    // La pantalla dice qué hacer: salir de la cuenta que está dentro.
    $this->post("/invitacion/{$token}", [])
        ->assertSessionHasErrors(['invitation' => 'Estás dentro como ana@fonda.mx. Sal de esa cuenta para aceptar esta invitación.']);

    expect(membresiaDeLupe()->user_id)->toBeNull();
});

it('el servicio tampoco liga una invitación a una cuenta de otro correo', function () {
    // La segunda barrera, por si algún día otro camino llama al servicio sin pasar por la pantalla.
    $token = ($this->tokenDe)(($this->invitar)($this->lupe, 'lupe@fonda.mx')->json('meta.invitation_link'));

    $invitaciones = app(MembershipInvitations::class);

    expect(fn () => $invitaciones->accept($invitaciones->findByToken($token), $this->owner))
        ->toThrow(ConflictHttpException::class, 'Esta invitación es para otro correo.');

    expect(membresiaDeLupe()->user_id)->toBeNull();
});

it('con la sesión abierta del destinatario basta con aceptar', function () {
    $lupe = User::factory()->create(['email' => 'lupe@fonda.mx', 'password' => 'clave-de-lupe-1']);

    $token = ($this->tokenDe)(($this->invitar)($this->lupe, 'lupe@fonda.mx')->json('meta.invitation_link'));

    ($this->comoVisitante)()->actingAs($lupe, 'web');

    expect($this->withoutVite()->get("/invitacion/{$token}")->viewData('page')['props']['mode'])->toBe('signed_in');

    $this->post("/invitacion/{$token}", [])->assertRedirect(route('admin.dashboard'));

    expect((int) membresiaDeLupe()->user_id)->toBe($lupe->id);
});

it('una invitación vencida no se acepta', function () {
    $token = ($this->tokenDe)(($this->invitar)($this->lupe, 'lupe@fonda.mx')->json('meta.invitation_link'));

    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => MembershipInvitation::query()->update(['expires_at' => now()->subMinute()]),
    );

    expect(($this->comoVisitante)()->withoutVite()->get("/invitacion/{$token}")->viewData('page')['props']['state'])->toBe('expired');

    $this->post("/invitacion/{$token}", [
        'first_name' => 'Lupe', 'paternal_surname' => 'Ríos',
        'password' => 'mi-clave-larga', 'password_confirmation' => 'mi-clave-larga',
    ])->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'lupe@fonda.mx')->exists())->toBeFalse();
});

it('un enlace inventado no dice nada de ningún negocio', function () {
    $props = ($this->comoVisitante)()->withoutVite()->get('/invitacion/'.str_repeat('a', 64))->assertOk()->viewData('page')['props'];

    expect($props['state'])->toBe('invalid');
    expect($props['business'])->toBeNull();
});

it('aceptar respeta el límite de personas del plan', function () {
    // Invitada no ocupa plaza; si mientras tanto se llenaron, aceptar no puede rebasarlas.
    $invitada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/memberships', ['email' => 'luis@fonda.mx', 'first_name' => 'Luis', 'paternal_surname' => 'Pérez'])
        ->assertCreated();

    $token = ($this->tokenDe)($invitada->json('meta.invitation_link'));

    // La dueña y Lupe ya ocupan las dos plazas.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => TenantLimit::create(['limit_key' => TenantLimitKey::MaxUsers, 'limit_value' => 2]),
    );

    ($this->comoVisitante)()->post("/invitacion/{$token}", [
        'first_name' => 'Luis', 'paternal_surname' => 'Pérez',
        'password' => 'clave-de-luis-1', 'password_confirmation' => 'clave-de-luis-1',
    ])->assertSessionHasErrors('invitation');

    expect(User::query()->where('email', 'luis@fonda.mx')->exists())->toBeFalse();
});

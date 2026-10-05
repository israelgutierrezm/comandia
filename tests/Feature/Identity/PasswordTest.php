<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Application\IssueApiToken;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Mail\ResetPasswordMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * CAMBIAR Y RECUPERAR LA CONTRASEÑA (diseño de acceso, fase 2)
 *
 * ## Lo que estas pruebas existen para demostrar
 *
 * **Que cambiarla o recuperarla deja fuera a quien la conocía.** Se cierran las sesiones de la app en todos los negocios
 * de la persona y las web caen en su siguiente petición; sólo sigue la sesión desde donde se cambió.
 *
 * **Que recuperarla no dice qué correos existen**, ni por el texto ni por lo que se envía, y que el enlace apunta a la
 * aplicación aunque la petición llegue con otro `Host`.
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

    // Ana también trabaja en otro negocio: la contraseña es una sola para los dos.
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

    $this->tokenPara = fn (TenantMembership $membresia, string $dispositivo): string => app(TenantContext::class)->runFor(
        (int) $membresia->tenant_id,
        fn (): string => app(IssueApiToken::class)->issue($membresia->refresh(), $dispositivo)->plainTextToken,
    );

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function asientosDeCuenta(int $tenantId, string $accion): int
{
    return app(TenantContext::class)->runFor($tenantId, fn (): int => AuditEntry::query()->where('action', $accion)->count());
}

// ---------------------------------------------------------------------------
// Cambiar mi contraseña
// ---------------------------------------------------------------------------

it('cambiar mi contraseña exige la actual y una nueva de 10 caracteres, distinta y repetida', function () {
    $pedir = fn (array $datos) => $this->actingAsSpa($this->owner, $this->tenant->id)->putJson('/api/v1/me/password', $datos);

    $pedir(['current_password' => 'no-es-la-mia', 'password' => 'otra-clave-larga', 'password_confirmation' => 'otra-clave-larga'])
        ->assertStatus(422)
        ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');

    $pedir(['current_password' => 'secreto-largo-123', 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'La contraseña nueva necesita al menos 10 caracteres.');

    $pedir(['current_password' => 'secreto-largo-123', 'password' => 'secreto-largo-123', 'password_confirmation' => 'secreto-largo-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'La contraseña nueva tiene que ser distinta de la actual.');

    $pedir(['current_password' => 'secreto-largo-123', 'password' => 'otra-clave-larga', 'password_confirmation' => 'no-coincide-123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'Las dos contraseñas nuevas no coinciden.');

    expect(Hash::check('secreto-largo-123', (string) $this->owner->refresh()->password))->toBeTrue();
});

it('cambiar mi contraseña conserva esta sesión y cierra todas las demás, en mis dos negocios', function () {
    ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');

    $hashAntes = (string) $this->owner->password;

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/me/password', [
            'current_password' => 'secreto-largo-123',
            'password' => 'nueva-clave-larga',
            'password_confirmation' => 'nueva-clave-larga',
        ])
        ->assertNoContent();

    expect(Hash::check('nueva-clave-larga', (string) $this->owner->refresh()->password))->toBeTrue();

    // Esta sesión sigue: la siguiente petición con la misma sesión pasa.
    $this->getJson('/api/v1/context')->assertOk();

    // Las sesiones de la app, en los dos negocios, se cerraron.
    expect(PersonalAccessToken::query()->count())->toBe(0);

    // Una sesión web que guardó el cifrado anterior ya no entra.
    Auth::forgetGuards();

    $this->flushSession()
        ->withHeader('Referer', (string) config('app.url'))
        ->withSession(['tenant_id' => $this->tenant->id, 'password_hash_web' => $hashAntes])
        ->actingAs($this->owner)
        ->getJson('/api/v1/context')
        ->assertUnauthorized();

    // Queda en la bitácora de los dos negocios de Ana.
    expect(asientosDeCuenta($this->tenant->id, AuditAction::PASSWORD_CHANGED))->toBe(1);
    expect(asientosDeCuenta($this->otroTenant->id, AuditAction::PASSWORD_CHANGED))->toBe(1);
});

it('desde la app, cambiarla conserva la sesión de ese teléfono y cierra las demás', function () {
    $token = ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');

    $this->withToken($token)
        ->putJson('/api/v1/me/password', [
            'current_password' => 'secreto-largo-123',
            'password' => 'nueva-clave-larga',
            'password_confirmation' => 'nueva-clave-larga',
        ])
        ->assertNoContent();

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['iPhone de Ana']);
});

// ---------------------------------------------------------------------------
// Olvidé mi contraseña
// ---------------------------------------------------------------------------

it('pedir el enlace responde lo mismo exista o no la cuenta, y sólo a la que existe le llega', function () {
    Mail::fake();

    $this->post('/olvide-contrasena', ['email' => 'ana@fonda.mx'])->assertRedirect();
    $existente = session('success');

    $this->post('/olvide-contrasena', ['email' => 'nadie@fonda.mx'])->assertRedirect();
    $inexistente = session('success');

    expect($existente)->toBeString()->not->toBeEmpty();
    expect($inexistente)->toBe($existente);

    Mail::assertQueued(ResetPasswordMail::class, 1);
    Mail::assertQueued(ResetPasswordMail::class, fn (ResetPasswordMail $correo): bool => $correo->hasTo('ana@fonda.mx'));
});

it('el enlace apunta a la aplicación aunque la petición llegue con otro Host', function () {
    // Envenenar el enlace: pedirlo para otra persona desde un dominio propio y recibir su token cuando haga clic.
    Mail::fake();

    // Con la URL completa: un encabezado `Host` suelto no llega, porque la petición de prueba toma el host de su URL.
    $this->post('http://atacante.example/olvide-contrasena', ['email' => 'ana@fonda.mx'])
        ->assertRedirect();

    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $correo): bool {
        return str_starts_with($correo->link, rtrim((string) config('app.url'), '/').'/restablecer/')
            && ! str_contains($correo->link, 'atacante.example');
    });
});

it('pedir enlaces tiene límite', function () {
    Mail::fake();

    foreach (range(1, 3) as $intento) {
        $this->post('/olvide-contrasena', ['email' => 'ana@fonda.mx'])->assertSessionHasNoErrors();
    }

    $this->post('/olvide-contrasena', ['email' => 'ana@fonda.mx'])->assertSessionHasErrors('email');
});

// ---------------------------------------------------------------------------
// Restablecer con el enlace
// ---------------------------------------------------------------------------

it('restablecer con el enlace cambia la contraseña, cierra todo, verifica el correo y no inicia sesión', function () {
    ($this->tokenPara)($this->ownerMembership, 'iPhone de Ana');
    ($this->tokenPara)($this->anaEnElOtro, 'iPad del café');

    $this->owner->forceFill(['email_verified_at' => null])->save();

    $token = Password::broker()->createToken($this->owner);

    $this->withoutVite()->get("/restablecer/{$token}?email=ana%40fonda.mx")->assertOk();

    $this->post('/restablecer', [
        'token' => $token,
        'email' => 'ana@fonda.mx',
        'password' => 'recuperada-larga',
        'password_confirmation' => 'recuperada-larga',
    ])->assertRedirect('/login');

    $this->owner->refresh();

    expect(Hash::check('recuperada-larga', (string) $this->owner->password))->toBeTrue();
    expect($this->owner->email_verified_at)->not->toBeNull();
    expect(PersonalAccessToken::query()->count())->toBe(0);
    expect(Auth::guard('web')->check())->toBeFalse();

    expect(asientosDeCuenta($this->tenant->id, AuditAction::PASSWORD_RESET))->toBe(1);
    expect(asientosDeCuenta($this->otroTenant->id, AuditAction::PASSWORD_RESET))->toBe(1);

    // El enlace se gasta: no sirve dos veces.
    $this->post('/restablecer', [
        'token' => $token,
        'email' => 'ana@fonda.mx',
        'password' => 'otra-vez-larga',
        'password_confirmation' => 'otra-vez-larga',
    ])->assertSessionHasErrors('token');

    expect(Hash::check('recuperada-larga', (string) $this->owner->refresh()->password))->toBeTrue();
});

it('un enlace de otro correo, inventado o sin cuenta responde lo mismo', function () {
    $token = Password::broker()->createToken($this->owner);

    $mensajes = [];

    foreach ([
        ['token' => $token, 'email' => 'beto@cafe.mx'],
        ['token' => str_repeat('a', 64), 'email' => 'ana@fonda.mx'],
        ['token' => $token, 'email' => 'nadie@fonda.mx'],
    ] as $intento) {
        $this->post('/restablecer', $intento + [
            'password' => 'recuperada-larga',
            'password_confirmation' => 'recuperada-larga',
        ])->assertSessionHasErrors('token');

        $mensajes[] = session('errors')->first('token');
    }

    expect(array_unique($mensajes))->toHaveCount(1);
    expect(Hash::check('secreto-largo-123', (string) $this->owner->refresh()->password))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Modules\Platform\Application\PlatformAccessLog;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Platform\Mail\PlatformPasswordChangedMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * LA CONTRASEÑA DE UN SUPER ADMINISTRADOR (D382)
 *
 * Sin «¿Olvidaste tu contraseña?» público: se cambia en «Mi cuenta» sabiendo la actual, o por la consola del servidor. Las
 * dos vías cierran las demás sesiones (la huella en `EnsureSuperAdmin` y el `remember_token` rotado), avisan por correo
 * y dejan constancia en el canal `platform`, sin la contraseña.
 */
function adminDePlataforma(string $password = 'plataforma-larga-123'): PlatformAdmin
{
    return PlatformAdmin::create(['name' => 'Operadora', 'email' => 'ops@comandia.mx', 'password' => $password])->refresh();
}

/** Cambia el canal `platform` por uno en memoria para leer lo que se escribió. */
function canalDePlataformaEnMemoria(): TestHandler
{
    config(['logging.channels.platform' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'info']]);

    return Log::channel('platform')->getLogger()->getHandlers()[0];
}

beforeEach(function () {
    Mail::fake();

    $this->cambiar = fn (array $datos) => $this->withoutVite()->from('/plataforma/mi-cuenta')->put('/plataforma/mi-cuenta/contrasena', array_merge([
        'current_password' => 'plataforma-larga-123',
        'password' => 'otra-clave-larga-1',
        'password_confirmation' => 'otra-clave-larga-1',
    ], $datos));
});

/*
|--------------------------------------------------------------------------
| «Mi cuenta»
|--------------------------------------------------------------------------
*/

it('«Mi cuenta» muestra los datos del admin y exige sesión de plataforma', function () {
    $this->withoutVite()->get('/plataforma/mi-cuenta')->assertRedirect('/plataforma/acceso');

    $admin = adminDePlataforma();

    $props = $this->actingAs($admin, 'platform')->withoutVite()
        ->get('/plataforma/mi-cuenta')
        ->assertOk()
        ->viewData('page');

    expect($props['component'])->toBe('Platform/Account');
    expect($props['props']['admin']['email'])->toBe('ops@comandia.mx');
    expect($props['props']['admin'])->not->toHaveKey('password');
});

it('con la contraseña actual equivocada, corta o igual a la actual, no cambia nada', function () {
    $admin = adminDePlataforma();
    $this->actingAs($admin, 'platform');

    ($this->cambiar)(['current_password' => 'equivocada-123'])->assertSessionHasErrors('current_password');
    ($this->cambiar)(['password' => 'corta-1', 'password_confirmation' => 'corta-1'])->assertSessionHasErrors('password');
    ($this->cambiar)(['password' => 'plataforma-larga-123', 'password_confirmation' => 'plataforma-larga-123'])->assertSessionHasErrors('password');

    expect(Hash::check('plataforma-larga-123', (string) $admin->refresh()->password))->toBeTrue();
    Mail::assertNothingQueued();
});

it('cambiarla conserva esta sesión, saca a las demás, avisa por correo y deja constancia sin la contraseña', function () {
    $canal = canalDePlataformaEnMemoria();
    $admin = adminDePlataforma();
    $rememberAntes = $admin->remember_token;

    // Esta sesión, con su huella ya guardada.
    $this->actingAs($admin, 'platform')->withoutVite()->get('/plataforma/mi-cuenta')->assertOk();
    $huellaAntes = session('password_hash_platform');
    expect($huellaAntes)->not->toBeNull();

    ($this->cambiar)([])
        ->assertRedirect('/plataforma/mi-cuenta')
        ->assertSessionHas('success', 'Contraseña actualizada. Tus otras sesiones se cerraron.');

    $admin->refresh();
    expect(Hash::check('otra-clave-larga-1', (string) $admin->password))->toBeTrue();
    expect($admin->remember_token)->not->toBeNull()->not->toBe($rememberAntes);

    // Esta sesión sigue.
    $this->withoutVite()->get('/plataforma/mi-cuenta')->assertOk();

    // Otra sesión, que guardó la huella de antes, vuelve al acceso con el aviso.
    Auth::forgetGuards();
    $this->flushSession()
        ->withSession(['password_hash_platform' => $huellaAntes])
        ->actingAs($admin, 'platform')
        ->withoutVite()
        ->get('/plataforma/mi-cuenta')
        ->assertRedirect('/plataforma/acceso')
        ->assertSessionHas('error', 'Tu sesión se cerró porque la contraseña cambió. Entra con la nueva.');

    Mail::assertQueued(PlatformPasswordChangedMail::class, fn (PlatformPasswordChangedMail $correo): bool => $correo->hasTo('ops@comandia.mx')
        && $correo->via === 'panel');

    $registro = collect($canal->getRecords())->sole(fn ($r): bool => $r->message === PlatformAccessLog::PASSWORD_CHANGED);
    expect($registro->context['admin_ulid'])->toBe($admin->ulid);
    expect($registro->context['via'])->toBe('panel');
    expect($registro->context)->toHaveKey('ip');
    expect(json_encode($canal->getRecords()))->not->toContain('otra-clave-larga-1')->not->toContain('plataforma-larga-123');
});

it('desde una sesión que entró por el acceso (sin actingAs), «Mi cuenta» valida contra el guardia de plataforma', function () {
    // `actingAs(…, 'platform')` cambia el guardia por omisión de la prueba; en producción sigue siendo `web`. Aquí se
    // entra como entraría un navegador, para que `current_password` tenga que nombrar su guardia.
    $admin = adminDePlataforma();

    $this->post('/plataforma/acceso', ['email' => 'ops@comandia.mx', 'password' => 'plataforma-larga-123'])->assertRedirect('/plataforma');

    ($this->cambiar)([])->assertRedirect('/plataforma/mi-cuenta')->assertSessionHas('success');

    expect(Hash::check('otra-clave-larga-1', (string) $admin->refresh()->password))->toBeTrue();
});

it('una sesión con la huella vigente sigue entrando, y una sin huella la adopta', function () {
    $admin = adminDePlataforma();

    $this->actingAs($admin, 'platform')->withoutVite()->get('/plataforma/mi-cuenta')->assertOk();

    Auth::forgetGuards();
    $this->flushSession()
        ->actingAs($admin, 'platform')
        ->withoutVite()
        ->get('/plataforma/mi-cuenta')
        ->assertOk()
        ->assertSessionHas('password_hash_platform');
});

it('una cookie de «Mantener la sesión abierta» emitida antes del cambio ya no entra', function () {
    $admin = adminDePlataforma();
    $admin->setRememberToken('token-de-antes');
    $admin->save();

    $guardia = Auth::guard('platform');
    $cookie = $admin->id.'|token-de-antes|'.$guardia->hashPasswordForCookie((string) $admin->password);
    $nombre = $guardia->getRecallerName();

    // Antes del cambio, la cookie sí entra.
    Auth::forgetGuards();
    $this->withCookie($nombre, $cookie)->withoutVite()->get('/plataforma/mi-cuenta')->assertOk();

    $this->artisan('comandia:platform-admin:password', ['email' => 'ops@comandia.mx'])
        ->expectsQuestion('Contraseña', 'otra-clave-larga-1')
        ->expectsQuestion('Repite la contraseña', 'otra-clave-larga-1')
        ->assertSuccessful();

    Auth::forgetGuards();
    $this->flushSession()->withCookie($nombre, $cookie)->withoutVite()->get('/plataforma/mi-cuenta')->assertRedirect('/plataforma/acceso');
});

it('una cookie de «Mantener la sesión abierta» emitida con otra contraseña no entra, aunque su token siga vigente', function () {
    $admin = adminDePlataforma();
    $admin->setRememberToken('token-vigente');
    $admin->save();

    $guardia = Auth::guard('platform');
    $cookie = $admin->id.'|token-vigente|'.$guardia->hashPasswordForCookie((string) $admin->password);
    $nombre = $guardia->getRecallerName();

    // La contraseña cambia por un camino que NO rota el token: la huella de la cookie es la única defensa.
    $admin->forceFill(['password' => 'otra-clave-larga-1'])->save();

    Auth::forgetGuards();
    $this->flushSession()
        ->withCookie($nombre, $cookie)
        ->withoutVite()
        ->get('/plataforma/mi-cuenta')
        ->assertRedirect('/plataforma/acceso')
        ->assertSessionHas('error', 'Tu sesión se cerró porque la contraseña cambió. Entra con la nueva.');
});

it('el sexto intento de cambiarla en un minuto se frena', function () {
    $admin = adminDePlataforma();
    $this->actingAs($admin, 'platform');

    foreach (range(1, 5) as $i) {
        ($this->cambiar)(['current_password' => 'equivocada-123'])
            ->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);
    }

    ($this->cambiar)([])->assertSessionHasErrors('current_password');
    expect(session('errors')->first('current_password'))->toContain('Demasiados intentos');

    // Ni con la buena: sigue igual.
    expect(Hash::check('plataforma-larga-123', (string) $admin->refresh()->password))->toBeTrue();
});

it('entrar guarda la huella de la contraseña', function () {
    adminDePlataforma();

    $this->post('/plataforma/acceso', ['email' => 'ops@comandia.mx', 'password' => 'plataforma-larga-123'])
        ->assertRedirect('/plataforma')
        ->assertSessionHas('password_hash_platform');
});

/*
|--------------------------------------------------------------------------
| Consola
|--------------------------------------------------------------------------
*/

it('crear con un correo que ya es super admin falla y no le toca nada', function () {
    $admin = adminDePlataforma();

    $this->artisan('comandia:platform-admin', ['email' => 'ops@comandia.mx', '--name' => 'Intrusa'])
        ->assertFailed();

    $admin->refresh();
    expect($admin->name)->toBe('Operadora');
    expect(Hash::check('plataforma-larga-123', (string) $admin->password))->toBeTrue();
    expect(PlatformAdmin::query()->count())->toBe(1);
});

it('crear deja constancia en el canal de plataforma', function () {
    $canal = canalDePlataformaEnMemoria();

    $this->artisan('comandia:platform-admin', ['email' => 'nueva@comandia.mx'])
        ->expectsQuestion('Contraseña', 'plataforma-larga-123')
        ->expectsQuestion('Repite la contraseña', 'plataforma-larga-123')
        ->assertSuccessful();

    $registro = collect($canal->getRecords())->sole(fn ($r): bool => $r->message === PlatformAccessLog::ADMIN_CREATED);
    expect($registro->context['admin_ulid'])->toBe(PlatformAdmin::query()->sole()->ulid);
    expect($registro->context['via'])->toBe('consola');
});

it('crear con dos contraseñas distintas falla', function () {
    $this->artisan('comandia:platform-admin', ['email' => 'nueva@comandia.mx'])
        ->expectsQuestion('Contraseña', 'plataforma-larga-123')
        ->expectsQuestion('Repite la contraseña', 'plataforma-larga-124')
        ->assertFailed();

    expect(PlatformAdmin::query()->count())->toBe(0);
});

it('la contraseña ya no se acepta como opción', function () {
    expect(fn () => $this->artisan('comandia:platform-admin', ['email' => 'nueva@comandia.mx', '--password' => 'plataforma-larga-123']))
        ->toThrow(InvalidOptionException::class);

    expect(PlatformAdmin::query()->count())->toBe(0);
});

it('cambiar la de alguien que no existe falla sin crear a nadie', function () {
    $this->artisan('comandia:platform-admin:password', ['email' => 'nadie@comandia.mx'])
        ->assertFailed();

    expect(PlatformAdmin::query()->count())->toBe(0);
});

it('cambiarla por consola respeta el mínimo de 10', function () {
    $admin = adminDePlataforma();

    $this->artisan('comandia:platform-admin:password', ['email' => 'ops@comandia.mx'])
        ->expectsQuestion('Contraseña', 'corta-123')
        ->expectsQuestion('Repite la contraseña', 'corta-123')
        ->assertFailed();

    expect(Hash::check('plataforma-larga-123', (string) $admin->refresh()->password))->toBeTrue();
});

it('cambiarla por consola no toca el nombre, rota el remember_token, saca a la sesión abierta, avisa y deja constancia', function () {
    $canal = canalDePlataformaEnMemoria();
    $admin = adminDePlataforma();
    $admin->setRememberToken('token-de-antes');
    $admin->save();

    $this->actingAs($admin, 'platform')->withoutVite()->get('/plataforma/mi-cuenta')->assertOk();

    $this->artisan('comandia:platform-admin:password', ['email' => 'OPS@comandia.mx'])
        ->expectsQuestion('Contraseña', 'otra-clave-larga-1')
        ->expectsQuestion('Repite la contraseña', 'otra-clave-larga-1')
        ->assertSuccessful();

    $admin->refresh();
    expect($admin->name)->toBe('Operadora');
    expect(Hash::check('otra-clave-larga-1', (string) $admin->password))->toBeTrue();
    expect($admin->remember_token)->not->toBe('token-de-antes');

    // La sesión que estaba abierta cae en su siguiente petición.
    Auth::forgetGuards();
    $this->actingAs($admin, 'platform')->withoutVite()->get('/plataforma/mi-cuenta')->assertRedirect('/plataforma/acceso');

    Mail::assertQueued(PlatformPasswordChangedMail::class, fn (PlatformPasswordChangedMail $correo): bool => $correo->via === 'consola');

    $registro = collect($canal->getRecords())->sole(fn ($r): bool => $r->message === PlatformAccessLog::PASSWORD_CHANGED);
    expect($registro->context['via'])->toBe('consola');
    expect($registro->context)->toHaveKey('os_user')->not->toHaveKey('ip');
});

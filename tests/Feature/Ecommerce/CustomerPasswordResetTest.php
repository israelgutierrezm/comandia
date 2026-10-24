<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Configuration\Infrastructure\Models\TenantMailSetting;
use App\Modules\Customers\Application\CustomerPasswordResets;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerPasswordReset;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Ecommerce\Jobs\SendCustomerPasswordResetLink;
use App\Modules\Ecommerce\Mail\CustomerPasswordResetMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * RECUPERAR LA CONTRASEÑA DEL CLIENTE DE LA TIENDA (D381)
 *
 * Pedir el enlace responde lo mismo exista o no la cuenta (la búsqueda vive en el trabajo). El enlace vale 60 minutos,
 * una vez, y sólo mientras el correo del cliente siga siendo al que se mandó. Restablecer no inicia sesión y saca a las
 * demás sesiones. Todo queda dentro del negocio del slug.
 */
function tiendaDeRecuperacion(int $tenantId, string $slug): Store
{
    return app(TenantContext::class)->runFor($tenantId, function () use ($slug): Store {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        return Store::create(['slug' => $slug, 'name' => 'Tienda '.$slug, 'is_active' => true])->refresh();
    });
}

/** Un cliente del negocio, creado dentro de su contexto. */
function clienteDeTienda(int $tenantId, array $datos): Customer
{
    return app(TenantContext::class)->runFor($tenantId, fn () => Customer::create($datos)->refresh());
}

/** Emite un enlace como lo haría el trabajo y devuelve el token en claro. */
function enlaceDe(int $tenantId, Customer $cliente): ?string
{
    return app(TenantContext::class)->runFor($tenantId, fn () => app(CustomerPasswordResets::class)->issue($cliente));
}

/** Corre el trabajo como en la cola: sin el contexto de ninguna petición. */
function correrTrabajoDeEnlace(int $tenantId, int $storeId, string $email): void
{
    app(TenantContext::class)->forget();

    dispatch_sync(new SendCustomerPasswordResetLink($tenantId, $storeId, $email));
}

beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->store = tiendaDeRecuperacion($this->tenant->id, 'fonda-tienda');

    $this->ema = clienteDeTienda($this->tenant->id, [
        'name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga',
    ]);

    app(TenantContext::class)->forget();

    $this->restablecer = fn (string $token, string $slug = 'fonda-tienda', string $nueva = 'nueva-contrasena') => $this->postJson(
        "/t/{$slug}/password/reset",
        ['token' => $token, 'password' => $nueva, 'password_confirmation' => $nueva],
    );
});

afterEach(fn () => app(TenantContext::class)->forget());

/** El único mensaje de un enlace que ya no sirve, sea cual sea la causa. */
function enlaceMuerto(): string
{
    return 'Este enlace ya no sirve: venció, ya se usó o se pidió otro. Pide uno nuevo.';
}

/*
|--------------------------------------------------------------------------
| Pedir el enlace
|--------------------------------------------------------------------------
*/

it('pedir el enlace responde lo mismo exista o no la cuenta, y siempre encola el trabajo', function () {
    Queue::fake();

    clienteDeTienda($this->tenant->id, ['name' => 'Pedro', 'phone' => '5522223333', 'email' => 'pedro@correo.mx']);
    clienteDeTienda($this->tenant->id, ['name' => 'Ina', 'phone' => '5544445555', 'email' => 'ina@correo.mx', 'password' => 'contrasena-larga', 'status' => 'inactive']);
    app(TenantContext::class)->forget();

    $respuestas = collect(['ema@correo.mx', 'nadie@correo.mx', 'pedro@correo.mx', 'ina@correo.mx'])
        ->map(fn (string $correo) => $this->postJson('/t/fonda-tienda/password/forgot', ['email' => $correo])
            ->assertStatus(202)
            ->json());

    expect($respuestas->unique()->count())->toBe(1);
    expect($respuestas->first()['data']['message'])->toContain('Si ese correo tiene cuenta en Tienda fonda-tienda');

    Queue::assertPushed(SendCustomerPasswordResetLink::class, 4);
    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);
});

it('el trabajo sólo manda el enlace a un cliente activo con contraseña', function () {
    Mail::fake();

    clienteDeTienda($this->tenant->id, ['name' => 'Pedro', 'phone' => '5522223333', 'email' => 'pedro@correo.mx']);
    clienteDeTienda($this->tenant->id, ['name' => 'Ina', 'phone' => '5544445555', 'email' => 'ina@correo.mx', 'password' => 'contrasena-larga', 'status' => 'inactive']);

    foreach (['ema@correo.mx', 'nadie@correo.mx', 'pedro@correo.mx', 'ina@correo.mx'] as $correo) {
        correrTrabajoDeEnlace($this->tenant->id, $this->store->id, $correo);
    }

    Mail::assertSentCount(1);
    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => $correo->hasTo('ema@correo.mx'));
});

it('el correo sale con el SMTP y el remitente del negocio', function () {
    Mail::fake();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => TenantMailSetting::create([
        'host' => 'smtp.fonda.mx', 'port' => 587, 'encryption' => 'tls', 'username' => 'ventas@fonda.mx',
        'password' => 'clave', 'from_address' => 'ventas@fonda.mx', 'from_name' => 'Fonda del Centro',
    ]));

    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => $correo->mailer === '_tenant'
        && $correo->envelope()->from?->address === 'ventas@fonda.mx'
        && $correo->envelope()->subject === 'Crea tu nueva contraseña en Tienda fonda-tienda');
});

it('sin correo propio, sale del de Comandia con el nombre de la tienda', function () {
    Mail::fake();

    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => $correo->envelope()->from?->address === config('mail.from.address')
        && $correo->envelope()->from?->name === 'Tienda fonda-tienda (vía Comandia)');
});

it('el enlace se arma con app.url y el slug de la base, aunque la petición llegue con otro Host', function () {
    Mail::fake();
    config(['app.url' => 'https://comandia.test']);

    $this->withHeader('Host', 'atacante.example')
        ->postJson('/t/fonda-tienda/password/forgot', ['email' => 'ema@correo.mx'])
        ->assertStatus(202);

    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => preg_match(
        '#^https://comandia\.test/t/fonda-tienda/restablecer/[A-Za-z0-9]{64}$#',
        $correo->link,
    ) === 1);
});

it('si el slug cambió entre la petición y el trabajo, el enlace usa el nuevo', function () {
    Mail::fake();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['slug' => 'fonda-nueva']));

    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => str_contains($correo->link, '/t/fonda-nueva/restablecer/'));
});

it('dos pedidos dentro de 5 minutos mandan un solo correo; después, otro que reemplaza al anterior', function () {
    Mail::fake();

    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');
    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertSentCount(1);

    $this->travel(6)->minutes();
    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertSentCount(2);
    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(1);
});

it('si el correo no sale, el enlace no queda vivo y el trabajo falla para reintentarse', function () {
    // Sin correo propio del negocio, `TenantMailer` manda por el mailer de Comandia: es el que se cae.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    expect(fn () => correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx'))
        ->toThrow(RuntimeException::class, 'SMTP caído');

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);
});

it('no emite el enlace si el correo cambió o el cliente se inactivó entre la búsqueda y el candado', function () {
    // El trabajo ya leyó a Ema con su correo; antes de que `issue()` tome el candado, el negocio cambia la base.
    $leida = app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::query()->findOrFail($this->ema->id));

    app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::table('customers')->where('id', $this->ema->id)->update(['email' => 'nueva@correo.mx']));
    expect(enlaceDe($this->tenant->id, $leida))->toBeNull();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::table('customers')->where('id', $this->ema->id)->update(['email' => 'ema@correo.mx', 'status' => 'inactive']));
    expect(enlaceDe($this->tenant->id, $leida))->toBeNull();

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);
});

it('no manda nada si la tienda se apagó o el módulo se desactivó antes de que corriera el trabajo', function () {
    Mail::fake();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['is_active' => false]));
    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->store->update(['is_active' => true]);
        app(ManageTenantModules::class)->set('Ecommerce', false);
    });
    correrTrabajoDeEnlace($this->tenant->id, $this->store->id, 'ema@correo.mx');

    Mail::assertNothingSent();
    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Restablecer
|--------------------------------------------------------------------------
*/

it('con el enlace se crea la contraseña nueva, el enlace se consume y queda en la bitácora sin actor', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    ($this->restablecer)($token)
        ->assertOk()
        ->assertJsonPath('data.message', 'Contraseña actualizada: entra con la nueva.');

    $ema = app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->ema->refresh());
    expect(Hash::check('nueva-contrasena', (string) $ema->password))->toBeTrue();

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);

    $asiento = app(TenantContext::class)->runFor($this->tenant->id, fn () => AuditEntry::query()
        ->where('action', AuditAction::CUSTOMER_PASSWORD_RESET)->sole());
    expect($asiento->auditable_ulid)->toBe($this->ema->ulid);
    expect($asiento->actor_membership_id)->toBeNull();
    expect($asiento->after)->toBe(['via' => 'enlace_correo']);
});

it('restablecer no inicia sesión; la vieja ya no entra y la nueva sí', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    ($this->restablecer)($token)->assertOk();

    $this->getJson('/t/fonda-tienda/me')->assertOk()->assertJsonPath('data', null);

    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])->assertStatus(422);
    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'nueva-contrasena'])->assertOk();
});

it('restablecer saca a las demás sesiones del cliente', function () {
    $cifradoAntes = (string) $this->ema->password;
    $token = enlaceDe($this->tenant->id, $this->ema);

    ($this->restablecer)($token)->assertOk();

    $ema = app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->ema->refresh());
    app(TenantContext::class)->forget();

    $this->flushSession()
        ->withSession(['password_hash_customer' => $cifradoAntes])
        ->actingAs($ema, 'customer')
        ->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('un enlace vencido no sirve', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    $this->travel(61)->minutes();

    ($this->restablecer)($token)->assertStatus(422)->assertJsonPath('title', enlaceMuerto());
});

it('un enlace usado no sirve otra vez', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    ($this->restablecer)($token)->assertOk();
    ($this->restablecer)($token, nueva: 'otra-contrasena-mas')->assertStatus(422)->assertJsonPath('title', enlaceMuerto());
});

it('pedir otro enlace mata el anterior', function () {
    $viejo = enlaceDe($this->tenant->id, $this->ema);
    $this->travel(6)->minutes();
    $nuevo = enlaceDe($this->tenant->id, $this->ema);

    ($this->restablecer)($viejo)->assertStatus(422)->assertJsonPath('title', enlaceMuerto());
    ($this->restablecer)($nuevo)->assertOk();
});

it('un enlace emitido antes de que cambiara el correo del cliente ya no sirve', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    // Por la base, sin pasar por la ficha (que además lo revoca): el enlace muere por la comparación de correo.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::table('customers')->where('id', $this->ema->id)->update(['email' => 'otra@correo.mx']));

    ($this->restablecer)($token)->assertStatus(422)->assertJsonPath('title', enlaceMuerto());

    $ema = app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->ema->refresh());
    expect(Hash::check('contrasena-larga', (string) $ema->password))->toBeTrue();
});

it('un enlace emitido antes de inactivar al cliente ya no sirve', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::table('customers')->where('id', $this->ema->id)->update(['status' => 'inactive']));

    ($this->restablecer)($token)->assertStatus(422)->assertJsonPath('title', enlaceMuerto());
});

it('un token mal formado responde lo mismo que uno vencido', function () {
    ($this->restablecer)('no-es-un-token')->assertStatus(422)->assertJsonPath('title', enlaceMuerto());
});

it('una contraseña corta o sin confirmar se rechaza con su motivo, sin gastar el enlace', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    $this->postJson('/t/fonda-tienda/password/reset', ['token' => $token, 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['password']]);

    ($this->restablecer)($token)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Aislamiento entre negocios y entre guardias
|--------------------------------------------------------------------------
*/

it('el mismo correo con cuenta en dos negocios: pedir en uno no toca al otro, y su enlace no sirve en el otro', function () {
    Mail::fake();

    $cafe = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    )['tenant'];
    tiendaDeRecuperacion($cafe->id, 'cafe-tienda');
    $emaEnElCafe = clienteDeTienda($cafe->id, ['name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'clave-del-cafe']);
    app(TenantContext::class)->forget();

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'ema@correo.mx'])->assertStatus(202);

    Mail::assertSentCount(1);
    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => CustomerPasswordReset::query()->count()))->toBe(1);
    expect(app(TenantContext::class)->runFor($cafe->id, fn () => CustomerPasswordReset::query()->count()))->toBe(0);

    // El token de la fonda presentado en la tienda del café no existe ahí.
    $token = null;
    Mail::assertSent(CustomerPasswordResetMail::class, function (CustomerPasswordResetMail $correo) use (&$token): bool {
        $token = substr($correo->link, -64);

        return true;
    });

    ($this->restablecer)($token, 'cafe-tienda')->assertStatus(422)->assertJsonPath('title', enlaceMuerto());

    $emaEnElCafe = app(TenantContext::class)->runFor($cafe->id, fn () => $emaEnElCafe->refresh());
    expect(Hash::check('clave-del-cafe', (string) $emaEnElCafe->password))->toBeTrue();
    expect(app(TenantContext::class)->runFor($cafe->id, fn () => AuditEntry::query()->where('action', AuditAction::CUSTOMER_PASSWORD_RESET)->count()))->toBe(0);

    // Y en su tienda sí sirve.
    ($this->restablecer)($token)->assertOk();
});

it('un correo que es a la vez del personal y de un cliente: la tienda no toca la recuperación del personal', function () {
    Mail::fake();

    clienteDeTienda($this->tenant->id, ['name' => 'Ana', 'phone' => '5566667777', 'email' => 'ana@fonda.mx', 'password' => 'clave-de-cliente']);
    app(TenantContext::class)->forget();

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'ana@fonda.mx'])->assertStatus(202);

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertSent(CustomerPasswordResetMail::class, fn (CustomerPasswordResetMail $correo): bool => $correo->hasTo('ana@fonda.mx'));

    $token = null;
    Mail::assertSent(CustomerPasswordResetMail::class, function (CustomerPasswordResetMail $correo) use (&$token): bool {
        $token = substr($correo->link, -64);

        return true;
    });

    ($this->restablecer)($token)->assertOk();

    // La contraseña de Ana como personal no cambió.
    expect(Hash::check('secreto-largo-123', (string) $this->owner->refresh()->password))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Límites y tienda apagada
|--------------------------------------------------------------------------
*/

it('el cuarto pedido en 15 minutos da 429, también con un correo que no existe', function () {
    Queue::fake();

    foreach (range(1, 3) as $i) {
        $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'nadie@correo.mx'])->assertStatus(202);
    }

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'nadie@correo.mx'])
        ->assertStatus(429)
        ->assertJsonPath('type', 'too_many_requests');

    Queue::assertPushed(SendCustomerPasswordResetLink::class, 3);
});

it('el pedido número 21 en una hora desde la misma conexión da 429', function () {
    Queue::fake();

    foreach (range(1, 20) as $i) {
        $this->postJson('/t/fonda-tienda/password/forgot', ['email' => "persona{$i}@correo.mx"])->assertStatus(202);
    }

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'otra@correo.mx'])->assertStatus(429);
});

it('el sexto intento de restablecer en un minuto da 429', function () {
    foreach (range(1, 5) as $i) {
        ($this->restablecer)(str_repeat('a', 64))->assertStatus(422);
    }

    ($this->restablecer)(str_repeat('a', 64))->assertStatus(429);
});

it('la página del enlace pinta la tienda con cabeceras que no dejan escapar el token', function () {
    $this->get('/t/fonda-tienda/restablecer/'.str_repeat('A', 64))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'")
        ->assertSee('fonda-tienda');

    expect($this->get('/t/fonda-tienda/restablecer/'.str_repeat('A', 64))->headers->get('Cache-Control'))->toContain('no-store');
});

it('con la tienda apagada o el módulo desactivado, todo responde 404', function () {
    $token = enlaceDe($this->tenant->id, $this->ema);

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->store->update(['is_active' => false]));

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'ema@correo.mx'])->assertNotFound();
    ($this->restablecer)($token)->assertNotFound();
    $this->get('/t/fonda-tienda/restablecer/'.$token)->assertNotFound();

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $this->store->update(['is_active' => true]);
        app(ManageTenantModules::class)->set('Ecommerce', false);
    });

    $this->postJson('/t/fonda-tienda/password/forgot', ['email' => 'ema@correo.mx'])->assertNotFound();
    ($this->restablecer)($token)->assertNotFound();
    $this->get('/t/fonda-tienda/restablecer/'.$token)->assertNotFound();
});

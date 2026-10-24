<?php

declare(strict_types=1);

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CUENTAS DE CLIENTE DE LA TIENDA (Iteración 8, Tanda C, parte 1)
 *
 * El cliente se registra e inicia sesión en la tienda pública (guard `customer`, aparte del personal). El slug resuelve el
 * negocio: un correo de un negocio no autentica en otro. Un cliente del POS sin credenciales (D43) las activa al
 * registrarse.
 */
function makeStore(int $tenantId, string $slug): void
{
    app(TenantContext::class)->runFor($tenantId, function () use ($slug): void {
        app(ManageTenantModules::class)->set('Ecommerce', true);
        Store::create(['slug' => $slug, 'name' => 'Tienda', 'is_active' => true]);
    });
}

beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    makeStore($this->tenant->id, 'fonda-tienda');
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('registra un cliente y lo deja autenticado', function () {
    $this->postJson('/t/fonda-tienda/register', [
        'name' => 'Laura Díaz', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga',
    ])->assertStatus(201)->assertJsonPath('data.email', 'laura@correo.mx');

    // Queda autenticada: `me` la devuelve.
    $this->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'laura@correo.mx');
});

it('la contraseña se guarda hasheada, nunca en claro', function () {
    $this->postJson('/t/fonda-tienda/register', [
        'name' => 'Laura', 'phone' => '5511112222', 'email' => 'laura@correo.mx', 'password' => 'contrasena-larga',
    ])->assertStatus(201);

    app(TenantContext::class)->set($this->tenant->id);
    $customer = Customer::query()->where('email', 'laura@correo.mx')->sole();
    expect($customer->password)->not->toBe('contrasena-larga');
    expect(password_verify('contrasena-larga', $customer->password))->toBeTrue();
});

it('rechaza un correo ya registrado en el negocio', function () {
    $this->postJson('/t/fonda-tienda/register', ['name' => 'A', 'phone' => '5511110000', 'email' => 'dup@correo.mx', 'password' => 'contrasena-larga'])->assertStatus(201);
    $this->postJson('/t/fonda-tienda/logout')->assertNoContent();

    $this->postJson('/t/fonda-tienda/register', ['name' => 'B', 'phone' => '5511119999', 'email' => 'dup@correo.mx', 'password' => 'otra-contrasena'])
        ->assertStatus(422);
});

it('activa las credenciales de un cliente del POS por su teléfono', function () {
    // Cliente creado en el POS sin credenciales (D43).
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Pedro', 'phone' => '5522223333']));

    $this->postJson('/t/fonda-tienda/register', ['name' => 'Pedro Pérez', 'phone' => '5522223333', 'email' => 'pedro@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(201)
        ->assertJsonPath('data.email', 'pedro@correo.mx');

    // Se activó el MISMO cliente, no se creó otro.
    app(TenantContext::class)->set($this->tenant->id);
    expect(Customer::query()->where('phone', '5522223333')->count())->toBe(1);
});

it('inicia y cierra sesión', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga']));

    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])
        ->assertOk()->assertJsonPath('data.email', 'ema@correo.mx');

    $this->getJson('/t/fonda-tienda/me')->assertOk()->assertJsonPath('data.email', 'ema@correo.mx');

    $this->postJson('/t/fonda-tienda/logout')->assertNoContent();
    $this->getJson('/t/fonda-tienda/me')->assertOk()->assertJsonPath('data', null);
});

it('rechaza una contraseña incorrecta', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga']));

    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'mala'])->assertStatus(422);
});

it('un cliente de un negocio no puede entrar en la tienda de otro', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga']));

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    makeStore($otro['tenant']->id, 'cafe-tienda');
    app(TenantContext::class)->forget();

    // El correo existe en la fonda, no en el café: el login en la tienda del café falla.
    $this->postJson('/t/cafe-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Sesión con huella, clientes inactivos y límites (D381, fase 1)
|--------------------------------------------------------------------------
*/

/** Un cliente con cuenta en la fonda. */
function clienteConCuenta(int $tenantId, string $status = 'active'): Customer
{
    return app(TenantContext::class)->runFor($tenantId, fn () => Customer::create([
        'name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga', 'status' => $status,
    ]));
}

it('un cliente inactivo no entra: recibe el mismo mensaje que una contraseña mala', function () {
    clienteConCuenta($this->tenant->id, 'inactive');

    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(422)
        ->assertJsonPath('title', 'Correo o contraseña incorrectos.');

    $this->getJson('/t/fonda-tienda/me')->assertOk()->assertJsonPath('data', null);
});

it('entrar guarda la huella de la contraseña en la sesión', function () {
    clienteConCuenta($this->tenant->id);

    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])
        ->assertOk()
        ->assertSessionHas('password_hash_customer');
});

it('una sesión abierta con la contraseña anterior ya no ve al cliente ni compra', function () {
    $ema = clienteConCuenta($this->tenant->id);
    $cifradoAntes = (string) $ema->password;

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $ema->update(['password' => 'otra-contrasena-larga']));
    app(TenantContext::class)->forget();

    $sesionVieja = fn () => $this->flushSession()
        ->withSession(['password_hash_customer' => $cifradoAntes])
        ->actingAs($ema->refresh(), 'customer');

    $sesionVieja()->getJson('/t/fonda-tienda/me')->assertOk()->assertJsonPath('data', null);
    $sesionVieja()->getJson('/t/fonda-tienda/orders')->assertUnauthorized();
    $sesionVieja()->postJson('/t/fonda-tienda/checkout', ['delivery_type' => 'pickup'])->assertUnauthorized();
});

it('una sesión con la huella vigente sigue, y una sin huella (de antes del despliegue) la adopta', function () {
    $ema = clienteConCuenta($this->tenant->id);
    app(TenantContext::class)->forget();

    $this->flushSession()
        ->withSession(['password_hash_customer' => (string) $ema->password])
        ->actingAs($ema, 'customer')
        ->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ema@correo.mx');

    $this->flushSession()
        ->actingAs($ema, 'customer')
        ->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ema@correo.mx')
        ->assertSessionHas('password_hash_customer');
});

it('el cliente que el negocio inactiva pierde su sesión abierta', function () {
    $ema = clienteConCuenta($this->tenant->id);
    $cifrado = (string) $ema->password;

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $ema->update(['status' => 'inactive']));
    app(TenantContext::class)->forget();

    $this->flushSession()
        ->withSession(['password_hash_customer' => $cifrado])
        ->actingAs($ema->refresh(), 'customer')
        ->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('sacar al cliente no toca el carrito ni la sesión del personal en el mismo navegador', function () {
    $ema = clienteConCuenta($this->tenant->id);
    $cifradoAntes = (string) $ema->password;

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $ema->update(['password' => 'otra-contrasena-larga']));
    app(TenantContext::class)->forget();

    $carrito = ['branch_ulid' => null, 'items' => [['article_ulid' => 'X', 'quantity' => 2]]];

    $this->flushSession()
        ->withSession([
            'password_hash_customer' => $cifradoAntes,
            'store_cart' => $carrito,
            'tenant_id' => $this->tenant->id,
            'password_hash_web' => 'huella-del-personal',
        ])
        ->actingAs($ema->refresh(), 'customer')
        ->getJson('/t/fonda-tienda/me')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertSessionHas('store_cart', $carrito)
        ->assertSessionHas('tenant_id', $this->tenant->id)
        ->assertSessionHas('password_hash_web', 'huella-del-personal')
        ->assertSessionMissing('password_hash_customer');
});

it('el sexto intento fallido en un minuto da 429, y entrar bien limpia el contador', function () {
    clienteConCuenta($this->tenant->id);
    $mala = ['email' => 'ema@correo.mx', 'password' => 'equivocada'];

    foreach (range(1, 4) as $i) {
        $this->postJson('/t/fonda-tienda/login', $mala)->assertStatus(422);
    }

    // Entrar bien limpia el contador: vuelven a caber cinco fallos.
    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])->assertOk();

    foreach (range(1, 5) as $i) {
        $this->postJson('/t/fonda-tienda/login', $mala)->assertStatus(422);
    }

    // El sexto, aun con la contraseña buena, espera.
    $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(429)
        ->assertJsonPath('title', 'Demasiados intentos de acceso. Vuelve a intentarlo en 1 minuto.');
});

it('el límite de acceso cuenta igual un correo que no existe, y es por negocio', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/t/fonda-tienda/login', ['email' => 'nadie@correo.mx', 'password' => 'x'])->assertStatus(422);
    }

    $this->postJson('/t/fonda-tienda/login', ['email' => 'nadie@correo.mx', 'password' => 'x'])->assertStatus(429);

    // El mismo correo en otro negocio lleva su propio contador.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    makeStore($otro['tenant']->id, 'cafe-tienda');
    app(TenantContext::class)->forget();

    $this->postJson('/t/cafe-tienda/login', ['email' => 'nadie@correo.mx', 'password' => 'x'])->assertStatus(422);
});

it('el registro tiene tope: el undécimo intento en una hora desde la misma conexión da 429', function () {
    $this->postJson('/t/fonda-tienda/register', ['name' => 'A', 'phone' => '5511110000', 'email' => 'dup@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(201);

    foreach (range(2, 10) as $i) {
        $this->postJson('/t/fonda-tienda/register', [
            'name' => 'B', 'phone' => sprintf('55111100%02d', $i), 'email' => 'dup@correo.mx', 'password' => 'contrasena-larga',
        ])->assertStatus(422);
    }

    $this->postJson('/t/fonda-tienda/register', ['name' => 'C', 'phone' => '5599990000', 'email' => 'nueva@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(429)
        ->assertJsonPath('type', 'too_many_requests');
});

it('un error de validación trae su motivo en errors, no sólo el título genérico', function () {
    $this->postJson('/t/fonda-tienda/register', ['name' => 'A', 'phone' => '5511110000', 'email' => 'a@correo.mx', 'password' => 'corta'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'validation_error')
        ->assertJsonStructure(['errors' => ['password']]);
});

it('no se le activan credenciales a un cliente del POS que el negocio inactivó', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Pedro', 'phone' => '5522223333', 'status' => 'inactive']));

    $this->postJson('/t/fonda-tienda/register', ['name' => 'Pedro', 'phone' => '5522223333', 'email' => 'pedro@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(422);

    app(TenantContext::class)->set($this->tenant->id);
    expect(Customer::query()->where('phone', '5522223333')->sole()->password)->toBeNull();
});

it('el límite de acceso no se evade escribiendo el correo con acentos', function () {
    clienteConCuenta($this->tenant->id);

    foreach (range(1, 5) as $i) {
        $this->postJson('/t/fonda-tienda/login', ['email' => 'ema@correo.mx', 'password' => 'equivocada'])->assertStatus(422);
    }

    // La base compara sin acentos: `ēma@corrēo.mx` ES la cuenta de Ema, y comparte su contador. Con `ē` y no con `é`: el
    // limitador de Laravel ya limpia las letras de Latin-1 (`htmlentities`), pero no las demás, que MySQL también iguala.
    $this->postJson('/t/fonda-tienda/login', ['email' => 'ēma@corrēo.mx', 'password' => 'equivocada'])->assertStatus(429);
});

it('el registro dice lo mismo de un teléfono con cuenta que de uno de un cliente inactivado', function () {
    clienteConCuenta($this->tenant->id);
    app(TenantContext::class)->runFor($this->tenant->id, fn () => Customer::create(['name' => 'Pedro', 'phone' => '5522223333', 'status' => 'inactive']));

    $conCuenta = $this->postJson('/t/fonda-tienda/register', ['name' => 'X', 'phone' => '5533334444', 'email' => 'x1@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(422)
        ->json('title');
    $inactivo = $this->postJson('/t/fonda-tienda/register', ['name' => 'X', 'phone' => '5522223333', 'email' => 'x2@correo.mx', 'password' => 'contrasena-larga'])
        ->assertStatus(422)
        ->json('title');

    expect($inactivo)->toBe($conCuenta);
});

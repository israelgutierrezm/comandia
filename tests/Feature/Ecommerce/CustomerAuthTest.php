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

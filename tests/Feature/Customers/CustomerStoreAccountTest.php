<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Customers\Application\CustomerPasswordResets;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerPasswordReset;
use App\Modules\Customers\Jobs\NotifyCustomerEmailChanged;
use App\Modules\Customers\Mail\CustomerEmailChangedMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * EL NEGOCIO CAMBIA EL CORREO DE UN CLIENTE CON CUENTA EN LA TIENDA (D381, decisión 5)
 *
 * Se permite, con contención: el enlace de recuperación vivo deja de servir, se avisa al correo ANTERIOR con el nuevo
 * enmascarado, el correo no se puede repetir ni quitar, y la bitácora guarda quién lo hizo.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->membershipId = $alta['membership']->id;

    app(TenantContext::class)->set($this->tenant->id);
    $this->conCuenta = Customer::create(['name' => 'Ema', 'phone' => '5533334444', 'email' => 'ema@correo.mx', 'password' => 'contrasena-larga'])->refresh();
    $this->sinCuenta = Customer::create(['name' => 'Pedro', 'phone' => '5522223333', 'email' => 'pedro@correo.mx'])->refresh();
    $this->token = app(CustomerPasswordResets::class)->issue($this->conCuenta);
    app(TenantContext::class)->forget();

    $this->editar = fn (Customer $cliente, array $datos) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$cliente->ulid}", array_merge(['name' => $cliente->name], $datos));
});

afterEach(fn () => app(TenantContext::class)->forget());

function enlacesVivosDe(int $tenantId, Customer $cliente): int
{
    return app(TenantContext::class)->runFor($tenantId, fn () => CustomerPasswordReset::query()->where('customer_id', $cliente->id)->count());
}

it('la ficha dice si el cliente tiene cuenta en la tienda', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->conCuenta->ulid}")
        ->assertOk()
        ->assertJsonPath('data.has_store_account', true);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->sinCuenta->ulid}")
        ->assertOk()
        ->assertJsonPath('data.has_store_account', false);
});

it('cambiar el correo de un cliente con cuenta mata su enlace, avisa al correo anterior y deja el rastro con el actor', function () {
    Mail::fake();

    ($this->editar)($this->conCuenta, ['email' => 'juan.perez@gmail.com'])->assertOk();

    expect(enlacesVivosDe($this->tenant->id, $this->conCuenta))->toBe(0);

    Mail::assertSent(CustomerEmailChangedMail::class, fn (CustomerEmailChangedMail $correo): bool => $correo->hasTo('ema@correo.mx')
        && $correo->maskedNewEmail === 'j***@g***.com'
        && $correo->businessName === 'Fonda del Centro');
    Mail::assertSentCount(1);

    $asiento = app(TenantContext::class)->runFor($this->tenant->id, fn () => AuditEntry::query()
        ->where('action', AuditAction::CUSTOMER_UPDATED)->latest('id')->firstOrFail());
    expect($asiento->actor_membership_id)->toBe($this->membershipId);
    expect($asiento->before['email'])->toBe('ema@correo.mx');
    expect($asiento->after['email'])->toBe('juan.perez@gmail.com');
});

it('el aviso se encola, una vez por cambio', function () {
    Queue::fake();

    ($this->editar)($this->conCuenta, ['email' => 'nuevo@correo.mx'])->assertOk();

    Queue::assertPushed(NotifyCustomerEmailChanged::class, 1);
});

it('cambiar sólo mayúsculas no es cambiar el correo', function () {
    Mail::fake();

    ($this->editar)($this->conCuenta, ['email' => 'EMA@correo.mx'])->assertOk();

    expect(enlacesVivosDe($this->tenant->id, $this->conCuenta))->toBe(1);
    Mail::assertNothingSent();
});

it('editar otro dato sin tocar el correo no mata el enlace ni avisa', function () {
    Mail::fake();

    ($this->editar)($this->conCuenta, ['email' => 'ema@correo.mx', 'notes' => 'Prefiere pickup'])->assertOk();

    expect(enlacesVivosDe($this->tenant->id, $this->conCuenta))->toBe(1);
    Mail::assertNothingSent();
});

it('a un cliente sin cuenta se le cambia el correo sin avisos', function () {
    Mail::fake();

    ($this->editar)($this->sinCuenta, ['email' => 'pedro.nuevo@correo.mx'])->assertOk();

    Mail::assertNothingSent();
});

it('inactivar a un cliente con cuenta mata su enlace, sin tener que volver a mandar su correo', function () {
    // Edición parcial: la petición no trae el correo, y no por eso intenta quitarlo.
    ($this->editar)($this->conCuenta, ['status' => 'inactive'])->assertOk();

    expect(enlacesVivosDe($this->tenant->id, $this->conCuenta))->toBe(0);
    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->conCuenta->refresh()->email))->toBe('ema@correo.mx');
});

it('el trabajo del aviso abre el contexto del negocio: corre igual fuera de la petición', function () {
    Mail::fake();
    app(TenantContext::class)->forget();

    dispatch_sync(new NotifyCustomerEmailChanged($this->tenant->id, $this->conCuenta->id, 'viejo@correo.mx'));

    Mail::assertSent(CustomerEmailChangedMail::class, fn (CustomerEmailChangedMail $correo): bool => $correo->hasTo('viejo@correo.mx')
        && $correo->maskedNewEmail === 'e***@c***.mx'
        && ! str_contains($correo->render(), 'Ema'));
});

it('la unicidad del correo es por negocio: otro negocio puede tener un cliente con el mismo', function () {
    $cafe = app(ProvisionTenant::class)->provision(
        businessName: 'Café', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    )['tenant'];
    app(TenantContext::class)->runFor($cafe->id, fn () => Customer::create(['name' => 'Otra', 'phone' => '5500000000', 'email' => 'compartido@correo.mx']));
    app(TenantContext::class)->forget();

    ($this->editar)($this->sinCuenta, ['email' => 'compartido@correo.mx'])->assertOk();

    expect(app(TenantContext::class)->runFor($cafe->id, fn () => Customer::query()->where('email', 'compartido@correo.mx')->count()))->toBe(1);
});

it('un correo que ya es de otro cliente se rechaza con su motivo, no con un error del servidor', function () {
    ($this->editar)($this->sinCuenta, ['email' => 'ema@correo.mx'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Ese correo ya es de otro cliente.');
});

it('no se le puede quitar el correo a un cliente con cuenta', function () {
    ($this->editar)($this->conCuenta, ['email' => null])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'Este cliente entra a la tienda en línea con su correo: no se puede quitar.');

    expect(enlacesVivosDe($this->tenant->id, $this->conCuenta))->toBe(1);
});

it('el correo nuevo se enmascara sin regalar el buzón', function () {
    expect(CustomerEmailChangedMail::mask('juan.perez@gmail.com'))->toBe('j***@g***.com');
    expect(CustomerEmailChangedMail::mask('ana@correo.com.mx'))->toBe('a***@c***.mx');
    expect(CustomerEmailChangedMail::mask('x@localhost'))->toBe('x***@l***');
});

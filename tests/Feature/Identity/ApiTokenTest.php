<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CreateMembership;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Str;

/**
 * Acceso por TOKEN para la app (Iteración 9). El hermano por token del acceso por sesión de la SPA: autentica global,
 * emite el token DESDE la membresía (D69) y, si la persona pertenece a varios negocios, pide elegir.
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

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('emite un token con credenciales válidas', function () {
    $respuesta = $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'iPad de la barra',
    ])->assertCreated();

    expect($respuesta->json('token'))->toBeString()->not->toBeEmpty();
    expect($respuesta->json('context.tenant.name'))->toBe('Fonda del Centro');
});

it('el token emitido autentica contra la API', function () {
    $token = $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'Tablet cocina',
    ])->json('token');

    // Con el token en la cabecera, `/context` dice quién soy: el token trae su tenant (D69), no la petición.
    $this->withToken($token)->getJson('/api/v1/context')->assertOk();
});

it('rechaza credenciales equivocadas con un mensaje genérico', function () {
    $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'incorrecta',
        'device_name' => 'x',
    ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Las credenciales no coinciden con nuestros registros.');
});

it('un correo inexistente da el MISMO mensaje, para no filtrar registros', function () {
    $this->postJson('/api/v1/auth/token', [
        'email' => 'noexiste@x.mx',
        'password' => 'lo-que-sea',
        'device_name' => 'x',
    ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Las credenciales no coinciden con nuestros registros.');
});

it('acepta un tenant_ulid explícito', function () {
    $respuesta = $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'x',
        'tenant_ulid' => $this->tenant->ulid,
    ])->assertCreated();

    expect($respuesta->json('context.tenant.ulid'))->toBe($this->tenant->ulid);
});

it('rechaza un tenant_ulid que no es de la persona', function () {
    $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'x',
        'tenant_ulid' => Str::upper((string) Str::ulid()),
    ])->assertStatus(422)->assertJsonPath('errors.tenant_ulid.0', 'No perteneces a ese negocio.');
});

it('pide elegir negocio cuando la persona pertenece a varios', function () {
    // Un segundo negocio, y metemos a la MISMA persona (correo global) en él.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-9',
    );
    app(TenantContext::class)->forget();

    app(TenantContext::class)->runFor($otro['tenant']->id, function () {
        $membresia = app(CreateMembership::class)->create(
            email: 'ana@fonda.mx',
            plainPassword: null,
            firstName: 'Ana',
            paternalSurname: 'Gómez',
            maternalSurname: null,
            employeeCode: 'A2',
            roleUlids: [],
            hasAllBranches: true,
        );

        // Nace invitada; se activa para que cuente como negocio al que Ana puede entrar.
        $membresia->update(['status' => MembershipStatus::Active]);
    });
    app(TenantContext::class)->forget();

    $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'x',
    ])->assertStatus(409)->assertJsonCount(2, 'memberships');
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Application\CreateMembership;
use App\Modules\Identity\Application\MembershipInvitations;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Http\Support\EmailThrottleKey;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\RateLimiter;
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

it('el límite no se evade escribiendo el correo con acentos', function () {
    foreach (range(1, 5) as $intento) {
        $this->postJson('/api/v1/auth/token', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta', 'device_name' => 'x'])
            ->assertStatus(422);
    }

    // La base iguala el ancho completo con la letra: `ａｎａ@fonda.mx` ES la cuenta de Ana y, con su contraseña, sacaría
    // un token con un contador nuevo: la app sería la puerta trasera del límite del acceso web (D387). Ni el limitador de
    // Laravel (sólo Latin-1) ni `Str::ascii` (que lo borra: la llave quedaba `@fonda.mx`) lo alcanzaban.
    expect(User::query()->where('email', 'ａｎａ@fonda.mx')->exists())->toBeTrue();

    $this->postJson('/api/v1/auth/token', ['email' => 'ａｎａ@fonda.mx', 'password' => 'secreto-largo-123', 'device_name' => 'x'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', fn (string $mensaje): bool => str_starts_with($mensaje, 'Demasiados intentos.'));
});

it('el intento ya está contado cuando se busca la cuenta para revisar la contraseña', function () {
    // Si se sumara al fallar, cada petición de una ráfaga simultánea vería el contador sin las demás y pasaría (D387).
    $llave = 'api-token:'.EmailThrottleKey::of('ana@fonda.mx').'|127.0.0.1';
    $contados = null;
    User::retrieved(function () use ($llave, &$contados): void {
        $contados ??= RateLimiter::attempts($llave);
    });

    $this->postJson('/api/v1/auth/token', ['email' => 'ana@fonda.mx', 'password' => 'incorrecta', 'device_name' => 'x'])
        ->assertStatus(422);

    expect($contados)->toBe(1);
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

    app(TenantContext::class)->runFor($otro['tenant']->id, function () use ($otro) {
        // El café la invita y Ana acepta con la cuenta que ya tenía: así se suma a un segundo negocio desde la fase 3
        // del diseño de acceso (ya no se liga una cuenta ajena sin su consentimiento).
        $alta = app(CreateMembership::class)->create(
            email: 'ana@fonda.mx',
            firstName: 'Ana',
            paternalSurname: 'Gómez',
            maternalSurname: null,
            employeeCode: 'A2',
            roleUlids: [],
            hasAllBranches: true,
            invitedBy: $otro['membership'],
        );

        app(MembershipInvitations::class)->accept(
            $alta->invitation->invitation,
            User::query()->where('email', 'ana@fonda.mx')->sole(),
        );
    });
    app(TenantContext::class)->forget();

    $this->postJson('/api/v1/auth/token', [
        'email' => 'ana@fonda.mx',
        'password' => 'secreto-largo-123',
        'device_name' => 'x',
    ])->assertStatus(409)->assertJsonCount(2, 'memberships');
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Resolución del nombre de una persona (D66).
 *
 * Es la decisión que cambió el diseño original, así que su comportamiento tiene que
 * estar clavado por pruebas: la precedencia, las dos formas de presentación, el
 * invariante I1 y el caso del empleado sin credenciales.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->resolver = app(MembershipNameResolver::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('usa el nombre del usuario cuando no hay perfil de empleado', function () {
    $user = User::factory()->create([
        'first_name' => 'Ana',
        'paternal_surname' => 'Gómez',
        'maternal_surname' => 'Ruiz',
    ]);

    $membresia = TenantMembership::factory()->create(['user_id' => $user->id]);
    $membresia->setRelation('employeeProfile', null);
    $membresia->setRelation('user', $user);

    $nombre = $this->resolver->resolve($membresia);

    expect($nombre->short())->toBe('Ana Gómez');
    expect($nombre->full())->toBe('Ana Gómez Ruiz');
});

it('el perfil de empleado GANA sobre el usuario', function () {
    // La precedencia que evita que un nombre mal escrito en el perfil global del SaaS
    // se imprima en las comandas de todos los restaurantes donde esa persona trabaja
    // sin que ninguno pueda corregirlo.
    $user = User::factory()->create([
        'first_name' => 'j',
        'paternal_surname' => 'ruiz',
        'maternal_surname' => null,
    ]);

    $membresia = TenantMembership::factory()->create(['user_id' => $user->id]);

    $perfil = EmployeeProfile::factory()->create([
        'membership_id' => $membresia->id,
        'legal_first_name' => 'Juan',
        'legal_paternal_surname' => 'Ruiz',
        'legal_maternal_surname' => 'Hernández',
    ]);

    $membresia->setRelation('employeeProfile', $perfil);
    $membresia->setRelation('user', $user);

    expect($this->resolver->resolve($membresia)->short())->toBe('Juan Ruiz');
});

it('resuelve el nombre del empleado SIN credenciales de acceso', function () {
    // El lavaloza en nómina que jamás inicia sesión (§4.1). Sin perfil de empleado no
    // tendría nombre, y una comanda sin mesero identificable es un problema real.
    $membresia = TenantMembership::factory()->withoutCredentials()->create();

    $perfil = EmployeeProfile::factory()->create([
        'membership_id' => $membresia->id,
        'legal_first_name' => 'Rosa',
        'legal_paternal_surname' => 'Martínez',
        'legal_maternal_surname' => 'Díaz',
    ]);

    $membresia->setRelation('employeeProfile', $perfil);
    $membresia->setRelation('user', null);

    expect($membresia->hasCredentials())->toBeFalse();
    expect($this->resolver->resolve($membresia)->short())->toBe('Rosa Martínez');
});

it('lanza excepción si se viola el invariante I1', function () {
    // Membresía sin usuario y sin perfil: una persona sin nombre. Si esto ocurre,
    // alguien creó la membresía por un camino que no pasa por el servicio de
    // aplicación, y hay que enterarse de inmediato.
    $membresia = TenantMembership::factory()->withoutCredentials()->create();
    $membresia->setRelation('employeeProfile', null);
    $membresia->setRelation('user', null);

    expect(fn () => $this->resolver->resolve($membresia))
        ->toThrow(RuntimeException::class, 'invariante I1');
});

it('omite el apellido materno de una persona extranjera', function () {
    $membresia = TenantMembership::factory()->withoutCredentials()->create();

    $perfil = EmployeeProfile::factory()->foreigner()->create([
        'membership_id' => $membresia->id,
        'legal_first_name' => 'John',
        'legal_paternal_surname' => 'Smith',
    ]);

    $membresia->setRelation('employeeProfile', $perfil);
    $membresia->setRelation('user', null);

    $nombre = $this->resolver->resolve($membresia);

    // `full()` no debe dejar un espacio colgando cuando falta el apellido materno.
    expect($nombre->full())->toBe('John Smith');
    expect($nombre->initials())->toBe('JS');
});

it('la carga previa evita el N+1 al resolver nombres en lote', function () {
    $membresias = TenantMembership::factory()->count(3)->create([
        'user_id' => fn () => User::factory()->create()->id,
    ]);

    foreach ($membresias as $m) {
        EmployeeProfile::factory()->create(['membership_id' => $m->id]);
    }

    // Con preventLazyLoading activo, resolver sin la carga previa lanzaría excepción.
    // Este test verifica que el helper del resolutor carga lo necesario: es la
    // diferencia entre la vista de piso funcionando y treinta consultas por render.
    $cargadas = MembershipNameResolver::eagerLoad(TenantMembership::query())->get();

    foreach ($cargadas as $m) {
        expect($this->resolver->resolve($m)->short())->not->toBeEmpty();
    }
});

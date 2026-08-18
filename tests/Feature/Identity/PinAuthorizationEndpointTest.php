<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\RateLimiter;

/** El permiso que se autoriza en estas pruebas. Tiene que existir en el catálogo cerrado (D10). */
const PERMISO_AUTORIZABLE = 'pos.discounts.apply_item';

/**
 * AUTORIZACIÓN POR PIN, POR HTTP (ADR-008)
 *
 * El servicio estaba probado; **el endpoint no se había llamado nunca**. Salió en la auditoría de cierre de
 * la Iteración 2, y es el más incómodo de la lista: es el único endpoint de `/api/v1` sin permiso —tiene su
 * propio mecanismo, deliberadamente distinto del rol activo— y el único con límite de intentos.
 *
 * O sea que la superficie HTTP que un atacante usaría para probar diez mil combinaciones de cuatro dígitos
 * no estaba ejercitada por ninguna prueba.
 *
 * ## Qué se cuida aquí
 *
 * Que el PIN **nunca vuelva en la respuesta**, que un PIN equivocado no diga si el código de empleado
 * existe, y que las dos cosas queden en la bitácora — la concedida y la negada. Una autorización que no deja
 * rastro no sirve para nada: su razón de existir es poder contestar «¿quién autorizó ese descuento?».
 */
beforeEach(function () {
    // El límite de intentos es real y se comparte entre pruebas del mismo proceso: sin limpiarlo, la
    // segunda prueba que falla un PIN heredaría los intentos de la primera y el resultado dependería del
    // orden de ejecución.
    RateLimiter::clear('pin');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con PIN',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Carmen',
        ownerPaternalSurname: 'Salgado',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    // Con usuario explícito: la factoría de membresías no crea uno, y sin usuario no hay roles —los roles
    // de Spatie cuelgan del usuario— así que la autorización se rechazaría por falta de permiso con el
    // MISMO 422 que un PIN incorrecto, que es justo lo que ADR-008 quiere que sea indistinguible.
    $autorizador = User::factory()->create(['first_name' => 'Luisa', 'paternal_surname' => 'Ortega']);

    $this->gerente = TenantMembership::factory()->withPin('4821')->create([
        'user_id' => $autorizador->id,
        'employee_code' => 'G001',
        'has_all_branches' => true,
    ]);

    // Quien autoriza tiene que TENER el permiso: es la excepción de ADR-008 —el permiso sale de la unión
    // de sus roles y no del rol activo, porque quien autoriza no está operando el sistema en ese
    // momento— y sin rol la autorización se rechaza aunque el PIN sea correcto.
    //
    // `guard_name` explícito: los permisos del catálogo se siembran para el guard `web`, y un rol de otro
    // guard tendría sus propios permisos —vacíos— sin que nada avise.
    $rol = Role::create(['name' => 'Autoriza descuentos', 'guard_name' => 'web']);
    $rol->givePermissionTo(PERMISO_AUTORIZABLE);
    $autorizador->assignRole($rol);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('autoriza con el código de empleado y el PIN correctos', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '4821',
            'permission' => PERMISO_AUTORIZABLE,
        ])
        ->assertCreated();

    // El PIN no vuelve, ni su hash, ni nada de lo que se mandó: la respuesta dice QUIÉN autorizó, que es
    // para lo que sirve.
    expect($respuesta->json())->not->toHaveKey('data.pin');

    $cuerpo = json_encode($respuesta->json());
    expect($cuerpo)->not->toContain('4821');
});

it('un PIN equivocado no revela si el código de empleado existe', function () {
    $inexistente = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'NOEXISTE',
            'pin' => '0000',
            'permission' => PERMISO_AUTORIZABLE,
        ]);

    RateLimiter::clear('pin');

    $existeMalPin = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '0000',
            'permission' => PERMISO_AUTORIZABLE,
        ]);

    // Misma respuesta en los dos casos. Si difirieran, el endpoint sería un oráculo de códigos de
    // empleado: bastaría comparar respuestas para enumerar la nómina antes de atacar el PIN.
    expect($inexistente->status())->toBe($existeMalPin->status());
});

it('deja en la bitácora tanto la autorización concedida como la negada', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001', 'pin' => '4821', 'permission' => PERMISO_AUTORIZABLE,
        ])->assertCreated();

    RateLimiter::clear('pin');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001', 'pin' => '9999', 'permission' => PERMISO_AUTORIZABLE,
        ]);

    app(TenantContext::class)->set($this->tenant->id);

    $acciones = AuditEntry::query()
        ->whereIn('action', [
            AuditAction::PIN_AUTHORIZATION_GRANTED,
            AuditAction::PIN_AUTHORIZATION_DENIED,
        ])
        ->pluck('action')
        ->all();

    // Las dos. La negada importa igual o más: una ráfaga de negadas es la señal de que alguien está
    // probando combinaciones.
    expect($acciones)->toContain(AuditAction::PIN_AUTHORIZATION_GRANTED)
        ->and($acciones)->toContain(AuditAction::PIN_AUTHORIZATION_DENIED);
});

it('el endpoint está limitado por intentos', function () {
    // ADR-008: un endpoint que compara cuatro dígitos sin límite es un espacio de 10 000 combinaciones
    // abierto a la fuerza bruta. El límite es parte de la decisión, no un extra.
    $estados = [];

    for ($intento = 0; $intento < 12; $intento++) {
        $estados[] = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/authorizations', [
                'employee_code' => 'G001', 'pin' => '0000', 'permission' => PERMISO_AUTORIZABLE,
            ])->status();
    }

    expect($estados)->toContain(429);
});

it('exige sesión: no es un endpoint público aunque no pida permiso', function () {
    // No tiene `can:` porque su mecanismo es el PIN y no el rol activo, y por eso está declarado como
    // excepción en el candado de rutas. Pero sigue detrás de `auth:sanctum`: la autorización por PIN
    // ocurre DENTRO de una sesión de trabajo, sobre una acción que alguien ya estaba haciendo.
    $this->postJson('/api/v1/authorizations', [
        'employee_code' => 'G001', 'pin' => '4821', 'permission' => PERMISO_AUTORIZABLE,
    ])->assertUnauthorized();
});

<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Finance\Application\SeedSystemPaymentMethods;
use App\Modules\Finance\Domain\Enums\PaymentMethodKind;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MÉTODOS DE PAGO (§6.3, adelantados a esta iteración por D232)
 *
 * ## La columna que hace posible el arqueo
 *
 * `affects_cash_drawer` decide qué pagos entraron al cajón de verdad. El efectivo sí; la tarjeta no —el dinero llega al
 * banco días después—; el crédito del cliente tampoco, porque no ha entrado nada. Sin esa distinción el corte sumaría
 * las tarjetas al efectivo esperado y **toda** caja saldría descuadrada.
 *
 * ## Los cuatro del sistema no se renombran
 *
 * Son la referencia con la que los cortes y los reportes agrupan. Un negocio que quiera otro nombre crea un método
 * propio — el mismo criterio que la Iteración 3 aplicó a los motivos de merma de sistema (D186), donde editar uno tenía
 * que devolver 422 y no 500.
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
    $this->branch = $alta['branch'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// La siembra
// ---------------------------------------------------------------------------

it('el alta de un negocio siembra los cuatro métodos del sistema', function () {
    $metodos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->json('data');

    // En su orden de botones, que es el orden por omisión del listado: el efectivo primero porque es el más usado y
    // cada toque de más se paga en la fila.
    expect(array_column($metodos, 'code'))->toBe(['CASH', 'CARD', 'TRANSFER', 'CUSTOMER_CREDIT']);

    $porCodigo = array_column($metodos, null, 'code');

    // El comportamiento de cada uno NO es configuración por omisión: es su naturaleza.
    expect($porCodigo['CASH']['affects_cash_drawer'])->toBeTrue()
        ->and($porCodigo['CASH']['allows_change'])->toBeTrue()
        ->and($porCodigo['CARD']['affects_cash_drawer'])->toBeFalse()
        ->and($porCodigo['TRANSFER']['requires_reference'])->toBeTrue()
        ->and($porCodigo['CUSTOMER_CREDIT']['affects_cash_drawer'])->toBeFalse();

    // Los cuatro son del sistema, y la interfaz lo sabe sin tener que deducirlo.
    foreach ($porCodigo as $metodo) {
        expect($metodo['is_system'])->toBeTrue()
            ->and($metodo['can_be_renamed'])->toBeFalse()
            ->and($metodo['can_be_deleted'])->toBeFalse();
    }
});

it('el crédito del cliente nace DESACTIVADO', function () {
    $metodos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->json('data');

    $porCodigo = array_column($metodos, null, 'code');

    // La naturaleza existe desde el principio porque `pos_payments` la va a citar, y crearla después obligaría a una
    // migración de datos en un negocio que ya estuviera operando. Pero el saldo del cliente llega en el paso 17: un
    // botón que cobra a crédito sin saldo que cargar dejaría la cuenta pagada y la deuda en ninguna parte.
    expect($porCodigo['CUSTOMER_CREDIT']['status'])->toBe('inactive')
        ->and($porCodigo['CASH']['status'])->toBe('active');
});

it('sembrar dos veces no duplica ni pisa lo que el negocio configuró', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // El negocio mueve el efectivo al final de los botones. Es SU decisión.
    $efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();
    $efectivo->update(['sort_order' => 999]);

    // Segunda pasada: es lo que corre al sincronizar un negocio que ya existía.
    $creados = app(SeedSystemPaymentMethods::class)->seed();

    expect($creados)->toBe(0)
        ->and(PaymentMethod::query()->count())->toBe(4)
        // Y no le devuelve el orden original: en cuanto el negocio toca algo, es suyo.
        ->and($efectivo->refresh()->sort_order)->toBe(999);
});

// ---------------------------------------------------------------------------
// Los invariantes del sistema
// ---------------------------------------------------------------------------

it('un método del sistema no se renombra, y responde 422 con el motivo', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $ulid = PaymentMethod::query()->where('code', 'CASH')->sole()->ulid;
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/payment-methods/{$ulid}", ['name' => 'Lana'])
        // 422 y no 500: lo que pasó no es que el sistema fallara, es que se pidió algo que el negocio no admite. Es la
        // corrección que la Iteración 3 tuvo que hacer con los motivos de merma (D186), aquí desde el principio.
        ->assertStatus(422);

    expect($respuesta->json('title'))->toContain('sistema');
});

it('un método del sistema no cambia sus banderas de comportamiento', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $ulid = PaymentMethod::query()->where('code', 'CARD')->sole()->ulid;
    app(TenantContext::class)->forget();

    // Poner la tarjeta como que afecta el cajón descuadraría todos los cortes desde ese día.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/payment-methods/{$ulid}", ['affects_cash_drawer' => true])
        ->assertStatus(422);
});

it('un método del sistema SÍ cambia su orden y su estado', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $ulid = PaymentMethod::query()->where('code', 'TRANSFER')->sole()->ulid;
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/payment-methods/{$ulid}", ['sort_order' => 5])
        ->assertOk()
        ->assertJsonPath('data.sort_order', 5);

    // Un negocio que no acepta transferencias la desactiva. Eso sí es suyo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/payment-methods/{$ulid}/toggle")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

it('un método del sistema no se borra ni por modelo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Se prueba SIN pasar por la aplicación: el invariante vive en el modelo justamente para que un servicio, un
    // comando de consola o un `tinker` no lo puedan saltar.
    $efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();

    expect(fn () => $efectivo->delete())->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------------
// Métodos propios del negocio
// ---------------------------------------------------------------------------

it('el negocio da de alta su propio método y declara su comportamiento', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', [
            'code' => 'vales',
            'name' => 'Vales de despensa',
            'affects_cash_drawer' => false,
            'requires_reference' => true,
            'allows_change' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'VALES')
        ->assertJsonPath('data.kind', 'custom')
        ->assertJsonPath('data.kind_label', 'Otro')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.can_be_renamed', true);
});

it('las tres banderas son obligatorias al dar de alta', function () {
    // No hay valor por omisión razonable: si el negocio no dice si su vale afecta el cajón, el corte lo va a sumar mal
    // en una dirección o en otra. Preguntar es más barato que adivinar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', ['code' => 'VALES', 'name' => 'Vales'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['affects_cash_drawer', 'requires_reference', 'allows_change']]);
});

it('no se puede crear un método de una naturaleza del sistema', function () {
    // Un segundo método de naturaleza `cash` daría dos fuentes de efectivo esperado y el corte dejaría de poder
    // explicarse.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', [
            'code' => 'CASH2',
            'name' => 'Otro efectivo',
            'kind' => PaymentMethodKind::Cash->value,
            'affects_cash_drawer' => true,
            'requires_reference' => false,
            'allows_change' => true,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['kind']]);
});

it('un método propio SÍ se renombra', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', [
            'code' => 'VALES',
            'name' => 'Vales',
            'affects_cash_drawer' => false,
            'requires_reference' => false,
            'allows_change' => false,
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/payment-methods/{$ulid}", ['name' => 'Vales de despensa'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Vales de despensa');

    // Pero su código NO: es la referencia estable con la que el diario y los reportes lo agrupan.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/payment-methods/{$ulid}", ['code' => 'OTRO'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

// ---------------------------------------------------------------------------
// La regla que protege la caja
// ---------------------------------------------------------------------------

it('no se puede desactivar el último método activo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Se dejan sólo dos activos y se apaga uno: hasta aquí, normal.
    $activos = PaymentMethod::query()->active()->orderBy('sort_order')->get();
    app(TenantContext::class)->forget();

    foreach ($activos->take($activos->count() - 1) as $metodo) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/payment-methods/{$metodo->ulid}/toggle")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    // Y el último no: un negocio sin métodos activos no puede cobrar, y el error aparecería en la caja con un cliente
    // esperando, sin que nadie relacione las dos cosas.
    //
    // 409 y no 422: no hay nada que corregir en el cuerpo de la petición — el problema es el estado del negocio. Mismo
    // criterio con el que D170 eligió 409 para la autorización pendiente.
    $ultimo = $activos->last();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/payment-methods/{$ultimo->ulid}/toggle")
        ->assertStatus(409);

    app(TenantContext::class)->set($this->tenant->id);
    expect(PaymentMethod::query()->active()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Aislamiento y autorización
// ---------------------------------------------------------------------------

it('los métodos de un negocio no se ven desde otro', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $ajeno = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn () => PaymentMethod::create([
            'code' => 'AJENO',
            'name' => 'Método del vecino',
            'kind' => PaymentMethodKind::Custom,
            'affects_cash_drawer' => false,
            'requires_reference' => false,
            'allows_change' => false,
        ])->ulid,
    );

    // Cada negocio ve SUS cuatro y nada más: la siembra corre por negocio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/payment-methods/{$ajeno}")
        ->assertNotFound();
});

it('quien cobra los ve y no los administra', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $cajero = User::factory()->create();

    $membresia = TenantMembership::factory()->create([
        'user_id' => $cajero->id,
        'employee_code' => 'C001',
        'has_all_branches' => true,
    ]);

    // El rol ACTIVO sale del `default_role_id` de la membresía y no de los roles asignados (D9): sin lo segundo, esta
    // prueba pasaría por la razón equivocada.
    $rol = Role::query()->where('name', RoleTemplates::CASHIER)->firstOrFail();
    $cajero->syncRoles([$rol]);
    $membresia->update(['default_role_id' => $rol->id]);

    app(TenantContext::class)->forget();

    // Ve: sin la lista de métodos, la pantalla de cobro llega sin con qué cobrar.
    $this->actingAsSpa($cajero, $this->tenant->id)
        ->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    // Y no administra: dar de alta un método es decidir con qué se puede pagar en el negocio, no cobrar.
    $this->actingAsSpa($cajero, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', [
            'code' => 'VALEBETO',
            'name' => 'Vale de Beto',
            'affects_cash_drawer' => false,
            'requires_reference' => false,
            'allows_change' => false,
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Auditoría y garantía estructural
// ---------------------------------------------------------------------------

it('el alta y el cambio de estado quedan en la bitácora', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/payment-methods', [
            'code' => 'VALES',
            'name' => 'Vales',
            'affects_cash_drawer' => false,
            'requires_reference' => false,
            'allows_change' => false,
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/payment-methods/{$ulid}/toggle")
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(AuditEntry::query()->where('action', AuditAction::PAYMENT_METHOD_CREATED)->count())->toBe(1)
        ->and(AuditEntry::query()->where('action', AuditAction::PAYMENT_METHOD_UPDATED)->count())->toBe(1);
});

it('la base impide dos métodos con el mismo código en el negocio', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Sin pasar por la aplicación (D218): un `unique` puede estar en el diseño y no en la base, y probarlo por la API
    // sólo probaría la validación del Form Request.
    expect(fn () => PaymentMethod::create([
        'code' => 'CASH',
        'name' => 'Efectivo duplicado',
        'kind' => PaymentMethodKind::Custom,
        'affects_cash_drawer' => true,
        'requires_reference' => false,
        'allows_change' => true,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

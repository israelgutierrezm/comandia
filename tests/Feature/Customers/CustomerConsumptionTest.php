<?php

declare(strict_types=1);

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL HISTORIAL DE CONSUMOS DEL EXPEDIENTE (Iteración 6, §6.6, D318)
 *
 * ## Lo que estas pruebas fijan
 *
 * Que el expediente ve los consumos del cliente —cuentas del POS— en orden del más reciente al más antiguo; que un
 * cliente sin consumos devuelve una lista vacía (el proveedor real, no el null-object); y que los consumos de un negocio
 * no se filtran a otro.
 *
 * Los consumos viven en `pos_accounts`, que es de `Pos`. `Customers` no los consulta —`Pos` ya depende de `Customers`, y
 * hacerlo al revés cerraría un ciclo—: los pregunta por una sonda del kernel que `Pos` implementa (D318). Que estas
 * pruebas pasen con datos reales confirma que el binding está enlazado al proveedor de `Pos`, no al vacío.
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
    $this->membershipId = $alta['membership']->id;

    app(TenantContext::class)->set($this->tenant->id);
    $this->cliente = Customer::create(['name' => 'Cliente Uno', 'created_by_membership_id' => $this->membershipId]);
    app(TenantContext::class)->forget();

    /** Una cuenta pagada del cliente, creada por modelo para ir al grano del historial. */
    $this->consumo = fn (Customer $cliente, int $branchId, int $membershipId, int $folio, string $total, string $when): PosAccount =>
        app(TenantContext::class)->runFor($cliente->tenant_id, fn (): PosAccount => PosAccount::create([
            'branch_id' => $branchId,
            'series' => 'A',
            'folio' => $folio,
            'kind' => 'dine_in',
            'status' => 'paid',
            'customer_id' => $cliente->id,
            'waiter_membership_id' => $membershipId,
            'opened_by_membership_id' => $membershipId,
            'opened_at' => $when,
            'paid_at' => $when,
            'total' => $total,
        ]));
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el expediente lista los consumos del cliente, del más reciente al más antiguo', function () {
    ($this->consumo)($this->cliente, $this->branch->id, $this->membershipId, 1, '100.00', '2026-08-20 10:00:00');
    ($this->consumo)($this->cliente, $this->branch->id, $this->membershipId, 2, '250.00', '2026-08-21 18:00:00');

    // Otro cliente del MISMO negocio, con su propia cuenta: no debe aparecer en el historial del primero. Esto fija que
    // se filtra por cliente y no sólo por tenant.
    $otroCliente = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => Customer::create(['name' => 'Cliente Dos', 'created_by_membership_id' => $this->membershipId]),
    );
    ($this->consumo)($otroCliente, $this->branch->id, $this->membershipId, 3, '999.00', '2026-08-21 20:00:00');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/consumos")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // El más reciente primero: la cuenta A-2 de $250. La de $999 del otro cliente no está.
    $respuesta->assertJsonPath('data.0.reference', 'A-2');
    $respuesta->assertJsonPath('data.0.total', '250.00');
    $respuesta->assertJsonPath('data.0.status', 'paid');
    $respuesta->assertJsonPath('data.1.reference', 'A-1');
    $respuesta->assertJsonPath('data.1.total', '100.00');
    expect(collect($respuesta->json('data'))->pluck('total'))->not->toContain('999.00');
});

it('un cliente sin consumos devuelve una lista vacía', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/consumos")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('los consumos de un cliente no se filtran a otro negocio', function () {
    ($this->consumo)($this->cliente, $this->branch->id, $this->membershipId, 1, '100.00', '2026-08-21 10:00:00');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    // El cliente es de otro negocio: el binding por tenant no lo encuentra → 404. Ni siquiera se llega a preguntar por
    // sus consumos.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/consumos")
        ->assertNotFound();

    // Y el segundo negocio, con su propio cliente y su propia cuenta, ve SÓLO la suya: la sonda respeta el scope de
    // tenant de `pos_accounts`.
    $clienteOtro = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn () => Customer::create(['name' => 'Cliente del Norte', 'created_by_membership_id' => $otro['membership']->id]),
    );
    ($this->consumo)($clienteOtro, $otro['branch']->id, $otro['membership']->id, 1, '75.00', '2026-08-21 12:00:00');

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/customers/{$clienteOtro->ulid}/consumos")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.total', '75.00');
});

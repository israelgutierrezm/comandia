<?php

declare(strict_types=1);

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Application\TakeoutNumberAllocator;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;

/**
 * El candado de transacción del asignador de números de mostrador.
 *
 * Va en `Unit` y con una conexión doble por la misma razón que el del asignador de folios: las pruebas de `Feature`
 * corren envueltas en la transacción de `RefreshDatabase`, así que allí `transactionLevel()` **nunca** vale 0.
 *
 * Lo aprendí escribiéndolo mal primero: puse la comprobación en la prueba de integración del paso 14 y no falló nunca,
 * porque el arnés ya tenía una transacción abierta. Un candado que ninguna prueba puede activar es un candado que nadie
 * sabe si funciona — que es exactamente lo que el encabezado del otro archivo llevaba una iteración advirtiendo.
 */
it('exige una transacción abierta para numerar el mostrador', function () {
    $conexion = Mockery::mock(ConnectionInterface::class);

    // El escenario real: dos pedidos simultáneos fuera de transacción. El FOR UPDATE liberaría el lock de inmediato y
    // los dos se llevarían el mismo número — dos personas levantándose por la misma bolsa.
    $conexion->shouldReceive('transactionLevel')->once()->andReturn(0);

    // Y no llega a tocar la base: el candado va antes de cualquier consulta.
    $conexion->shouldNotReceive('table');

    $allocator = new TakeoutNumberAllocator($conexion, new TenantContext);

    expect(fn () => $allocator->next(new Branch(['timezone' => 'America/Mexico_City'])))
        ->toThrow(LogicException::class, 'exige una transacción abierta');
});

it('con transacción abierta pasa del candado y consulta el contador', function () {
    $conexion = Mockery::mock(ConnectionInterface::class);
    $conexion->shouldReceive('transactionLevel')->once()->andReturn(1);

    // Que el candado no bloquea el camino legítimo. `table()` lanza para no doblar el query builder entero.
    $conexion->shouldReceive('table')->once()->andThrow(new DomainException('llegó a la base'));

    $context = new TenantContext;
    $context->set(1);

    $allocator = new TakeoutNumberAllocator($conexion, $context);

    expect(fn () => $allocator->next(new Branch(['timezone' => 'America/Mexico_City'])))
        ->toThrow(DomainException::class, 'llegó a la base');
});

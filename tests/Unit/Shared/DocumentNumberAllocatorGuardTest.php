<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;

/**
 * El candado de transacción del asignador de folios.
 *
 * Va en `Unit` y con una conexión doble por una razón concreta: las pruebas de
 * `Feature` corren envueltas en la transacción de `RefreshDatabase`, así que allí
 * `transactionLevel()` **nunca** vale 0 y este candado sería imposible de verificar.
 * Un candado que ninguna prueba puede activar es un candado que nadie sabe si
 * funciona.
 *
 * Se usa un doble de Mockery en lugar de implementar `ConnectionInterface` a mano:
 * la interfaz de Laravel cambia de firma entre versiones y una implementación
 * completa se rompería en cada actualización por razones que no tienen nada que ver
 * con lo que este test verifica.
 */
it('exige una transacción abierta', function () {
    $conexion = Mockery::mock(ConnectionInterface::class);

    // El escenario real: alguien llama al asignador fuera de transacción. El
    // FOR UPDATE liberaría el lock de inmediato y dos peticiones simultáneas tomarían
    // el mismo folio.
    $conexion->shouldReceive('transactionLevel')->once()->andReturn(0);

    // Y no debe llegar a tocar la base: el candado va antes de cualquier consulta.
    $conexion->shouldNotReceive('table');

    $allocator = new DocumentNumberAllocator($conexion, new TenantContext);

    expect(fn () => $allocator->next(1, 'account', 'CEN'))
        ->toThrow(LogicException::class, 'exige una transacción abierta');
});

it('con transacción abierta pasa del candado y consulta la secuencia', function () {
    $conexion = Mockery::mock(ConnectionInterface::class);
    $conexion->shouldReceive('transactionLevel')->once()->andReturn(1);

    // Verifica lo complementario: que el candado no bloquea el camino legítimo.
    // `table()` lanza para no tener que doblar todo el query builder; lo que importa
    // es que se haya llegado hasta aquí.
    $conexion->shouldReceive('table')->once()->andThrow(new DomainException('llegó a la base'));

    $context = new TenantContext;
    $context->set(1);

    $allocator = new DocumentNumberAllocator($conexion, $context);

    expect(fn () => $allocator->next(1, 'account', 'CEN'))
        ->toThrow(DomainException::class, 'llegó a la base');
});

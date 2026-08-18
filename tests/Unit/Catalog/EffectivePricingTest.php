<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\EffectivePricing;

/**
 * LA CASCADA DE DOS NIVELES (§6.1)
 *
 * Dominio puro: los cuatro casos de la cascada sin tocar la base. `NULL` en el override significa **heredar**,
 * igual que en la configuración jerárquica del kernel.
 */
it('sin override, hereda el dato maestro', function () {
    $p = EffectivePricing::resolve('85.00', true, null, null);

    expect($p->price)->toBe('85.00');
    expect($p->priceIsOverridden)->toBeFalse();
    expect($p->isAvailableInPos)->toBeTrue();
    expect($p->availabilityIsOverridden)->toBeFalse();
});

it('el override de precio gana', function () {
    $p = EffectivePricing::resolve('85.00', true, '95.00', null);

    expect($p->price)->toBe('95.00');
    expect($p->priceIsOverridden)->toBeTrue();

    // Y la disponibilidad sigue heredando: las dos dimensiones son independientes.
    expect($p->isAvailableInPos)->toBeTrue();
    expect($p->availabilityIsOverridden)->toBeFalse();
});

it('el override de disponibilidad gana, y FALSE no es lo mismo que heredar', function () {
    // Es la distinción que obliga a que la columna sea nullable: `false` dice "no está disponible aquí" y
    // `null` dice "usa lo del negocio". Castear NULL a false haría desaparecer platillos sin que nadie lo
    // pidiera.
    $apagado = EffectivePricing::resolve('85.00', true, null, false);

    expect($apagado->isAvailableInPos)->toBeFalse();
    expect($apagado->availabilityIsOverridden)->toBeTrue();

    $heredado = EffectivePricing::resolve('85.00', true, null, null);

    expect($heredado->isAvailableInPos)->toBeTrue();
});

it('un override puede ENCENDER lo que el negocio tiene apagado', function () {
    // La cascada va en los dos sentidos: un platillo retirado del catálogo general puede seguir vivo en la
    // sucursal que lo pidió.
    $p = EffectivePricing::resolve('85.00', false, null, true);

    expect($p->isAvailableInPos)->toBeTrue();
    expect($p->availabilityIsOverridden)->toBeTrue();
});

it('un override igual al maestro SIGUE siendo override', function () {
    // No es un detalle cosmético: el día que el negocio suba su precio a $90, esta sucursal se queda en $85
    // porque decidió ese número. Distinguirlo es lo que permite explicarlo en pantalla.
    $p = EffectivePricing::resolve('85.00', true, '85.00', null);

    expect($p->price)->toBe('85.00');
    expect($p->priceIsOverridden)->toBeTrue();
});

it('sin precio en ningún nivel, el precio es null', function () {
    // Un insumo no tiene precio de venta, y eso no es cero: cero diría que se regala.
    $p = EffectivePricing::resolve(null, true, null, null);

    expect($p->price)->toBeNull();
});

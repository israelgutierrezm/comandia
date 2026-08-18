<?php

declare(strict_types=1);

use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Costing\Domain\Enums\RoundingMode;

/**
 * REDONDEO DEL PRECIO SUGERIDO (D15)
 *
 * Dominio puro. Redondea **hacia arriba** en los modos de múltiplo, no al más cercano: un precio sugerido es
 * un piso de rentabilidad, y bajarlo para llegar al múltiplo cercano recortaría el markup que el negocio
 * pidió.
 */
it('sin redondeo deja el monto con dos decimales', function () {
    expect(RoundingMode::None->apply('123.456'))->toBe('123.46');
    expect(RoundingMode::None->apply('47'))->toBe('47.00');
});

it('al peso sube al siguiente entero', function () {
    expect(RoundingMode::Integer->apply('47.01'))->toBe('48.00');
    expect(RoundingMode::Integer->apply('47.99'))->toBe('48.00');
});

it('a múltiplos de 5 sube al siguiente múltiplo, no al más cercano', function () {
    // $47 sugiere $50, no $45. Bajarlo a 45 recortaría el markup configurado.
    expect(RoundingMode::Multiple5->apply('47.00'))->toBe('50.00');
    expect(RoundingMode::Multiple5->apply('41.00'))->toBe('45.00');
    expect(RoundingMode::Multiple5->apply('45.01'))->toBe('50.00');
});

it('a múltiplos de 10 igual', function () {
    expect(RoundingMode::Multiple10->apply('41.00'))->toBe('50.00');
    expect(RoundingMode::Multiple10->apply('99.99'))->toBe('100.00');
});

it('un monto que ya es múltiplo exacto NO sube al siguiente', function () {
    // Sin esta comprobación, $50 con múltiplos de 5 sugeriría $55: subir el precio de algo que ya estaba
    // redondo, en cada consulta.
    expect(RoundingMode::Multiple5->apply('50.00'))->toBe('50.00');
    expect(RoundingMode::Multiple10->apply('50.00'))->toBe('50.00');
    expect(RoundingMode::Integer->apply('48.00'))->toBe('48.00');
});

it('el cero se queda en cero', function () {
    // Un costo de cero es legítimo —un insumo regalado por el proveedor— y no debe convertirse en un
    // sugerido de $5 por el redondeo.
    expect(RoundingMode::Multiple5->apply('0.00'))->toBe('0.00');
    expect(RoundingMode::Integer->apply('0.00'))->toBe('0.00');
});

it('los valores del enum coinciden con los del ajuste de configuración', function () {
    // El enum es la forma tipada de `pricing.rounding_mode`. Si divergieran, `from()` lanzaría en producción
    // — preferible a redondear en silencio de forma distinta a la configurada, pero mejor que no ocurra.
    $permitidos = SettingCatalog::get('pricing.rounding_mode')->allowed;

    $delEnum = array_map(fn (RoundingMode $m): string => $m->value, RoundingMode::cases());

    expect($delEnum)->toEqualCanonicalizing($permitidos);
});

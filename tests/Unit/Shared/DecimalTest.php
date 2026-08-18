<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Support\Decimal;

/**
 * ARITMÉTICA DECIMAL EXACTA
 *
 * Dominio puro. Tiene suite propia porque es la base de todo costo del sistema y su contrato es sutil:
 * `bcmath` **trunca**, y truncar sistemáticamente sesga todos los costos hacia abajo —siempre en el mismo
 * sentido— con lo que el margen que el sistema reporta sale optimista sin que nada falle.
 */
it('redondea media-arriba, como espera un humano', function () {
    expect(Decimal::round('0.12345', 4))->toBe('0.1235');
    expect(Decimal::round('0.12344', 4))->toBe('0.1234');

    // El caso del empate exacto: 0.5 sube.
    expect(Decimal::round('2.5', 0))->toBe('3');
    expect(Decimal::round('1.005', 2))->toBe('1.01');
});

it('redondea negativos alejándose de cero', function () {
    expect(Decimal::round('-0.12345', 4))->toBe('-0.1235');
    expect(Decimal::round('-2.5', 0))->toBe('-3');
});

it('no produce «-0»', function () {
    // Un costo de «-0.0000» en pantalla es un número que nadie sabe interpretar.
    expect(Decimal::round('-0.00001', 4))->toBe('0.0000');
});

it('dividir NO es bcdiv seguido de round a la misma escala', function () {
    // El defecto real que motivó `Decimal::divide`, con el número exacto que lo destapó.
    //
    // `bcdiv('10', '600', 8)` trunca a 0.01666666. Redondear eso a 8 decimales no corrige nada: el dígito
    // que decidiría el redondeo ya se perdió. Al costear tres niveles en cascada, ese truncamiento se
    // propagaba hacia arriba y movía el cuarto decimal del costo del platillo.
    expect(bcdiv('10', '600', 8))->toBe('0.01666666');
    expect(Decimal::round(bcdiv('10', '600', 8), 8))->toBe('0.01666666');

    // Con dígitos de guarda, el resultado correcto.
    expect(Decimal::divide('10', '600', 8))->toBe('0.01666667');
});

it('divide exacto cuando la división es exacta', function () {
    expect(Decimal::divide('600', '25', 4))->toBe('24.0000');
    expect(Decimal::divide('1', '8', 4))->toBe('0.1250');
});

it('divide redondeando media-arriba', function () {
    // 200 / 3 = 66.6666|66… → el quinto decimal es 6, así que sube.
    expect(Decimal::divide('200', '3', 4))->toBe('66.6667');

    // 100 / 3 = 33.3333|33… → el quinto decimal es 3, así que se queda.
    expect(Decimal::divide('100', '3', 4))->toBe('33.3333');
});

it('compara por valor y no por texto', function () {
    // '16.00' y '16.0000' son el mismo número y distintas cadenas: es el caso que aparece al comparar lo
    // que se escribió con lo que MySQL devuelve.
    expect(Decimal::equals('16.00', '16.0000'))->toBeTrue();
    expect(Decimal::equals('16.00', '16.0001'))->toBeFalse();

    // Y a escala más gruesa, la diferencia deja de importar: es lo que permite preguntar "¿cambió el costo?"
    // sin que un decimal de más cuente como cambio.
    expect(Decimal::equals('16.00001', '16.00002', 4))->toBeTrue();
});

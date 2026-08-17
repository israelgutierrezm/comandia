<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Catalog\Domain\Exceptions\IncompatibleUnitDimensionException;
use App\Modules\Catalog\Domain\UnitConverter;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;

/**
 * CONVERSIÓN DE UNIDADES (D22)
 *
 * Es la pieza de la que depende todo costo del sistema. Un error aquí no produce un error visible:
 * produce costos equivocados en cada receta, y de ahí precios sugeridos y márgenes equivocados. Por
 * eso tiene suite propia y por eso las aserciones son sobre valores exactos y no sobre aproximaciones.
 *
 * Necesita base de datos porque las unidades son modelos con scope de tenant, aunque el conversor en
 * sí sea dominio puro.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->converter = app(UnitConverter::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('el alta del negocio siembra las unidades para poder capturar artículos', function () {
    // `articles.base_unit_id` es NOT NULL, así que un tenant con cero unidades no puede capturar ni un
    // artículo. El sembrado viaja por evento de dominio: el kernel anuncia el alta y el catálogo
    // escucha, porque el kernel NO puede depender de un módulo de dominio (§2, regla 1).
    $codes = Unit::query()->pluck('code')->all();

    expect($codes)->toContain('g', 'kg', 'ml', 'l', 'pza');
});

it('convierte dentro de la misma dimensión', function () {
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $g = Unit::query()->where('code', 'g')->firstOrFail();

    // 2.5 kg son 2500 g. Comparación numérica y no de cadenas: '2500' y '2500.00000000' son el mismo
    // número, y compararlas como texto haría fallar la prueba por la escala.
    expect(bccomp($this->converter->convert('2.5', $kg, $g), '2500', 8))->toBe(0);
    expect(bccomp($this->converter->convert('750', $g, $kg), '0.75', 8))->toBe(0);
});

it('convertir a la misma unidad no cambia el valor', function () {
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();

    expect(bccomp($this->converter->convert('3.1416', $kg, $kg), '3.1416', 8))->toBe(0);
});

it('RECHAZA convertir entre dimensiones distintas', function () {
    // No es un caso a resolver con una aproximación: un limón no pesa lo que una sandía, así que
    // piezas a kilogramos sólo es cierto POR ARTÍCULO. Eso son las presentaciones de compra.
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $l = Unit::query()->where('code', 'l')->firstOrFail();

    expect(fn () => $this->converter->convert('1', $kg, $l))
        ->toThrow(IncompatibleUnitDimensionException::class);

    expect($this->converter->isCompatible($kg, $l))->toBeFalse();
});

it('el mensaje del rechazo dice qué hacer, no sólo que falló', function () {
    // Un error que sólo dice "no se puede" deja al usuario sin saber cómo capturar lo que quería.
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $pza = Unit::query()->where('code', 'pza')->firstOrFail();

    try {
        $this->converter->convert('1', $kg, $pza);
        $this->fail('Debió lanzar excepción.');
    } catch (IncompatibleUnitDimensionException $e) {
        expect($e->getMessage())->toContain('presentación de compra');
        expect($e->getMessage())->toContain('kg');
        expect($e->getMessage())->toContain('pza');
    }
});

it('convierte a la unidad base del sistema', function () {
    $l = Unit::query()->where('code', 'l')->firstOrFail();

    // La base de volumen es el mililitro, y es constante del código: si fuera dato del tenant,
    // cambiarla alteraría el significado de todos los factores ya capturados.
    expect(UnitDimension::Volume->baseUnitCode())->toBe('ml');
    expect(bccomp($this->converter->toSystemBase('1.5', $l), '1500', 8))->toBe(0);
});

it('no pierde precisión con factores de muchos decimales', function () {
    // El caso que motiva `bcmath` en lugar de float: una unidad diminuta cuyo factor tiene ocho
    // decimales, convertida y devuelta. Con float, la ida y vuelta no devuelve el valor original.
    $gota = Unit::factory()->create([
        'code' => 'gota',
        'name' => 'Gota',
        'dimension' => UnitDimension::Volume,
        'factor_to_base' => '0.05000000',
    ]);

    $ml = Unit::query()->where('code', 'ml')->firstOrFail();

    // 20 gotas = 1 ml.
    expect(bccomp($this->converter->convert('20', $gota, $ml), '1', 8))->toBe(0);

    // Y de vuelta, exacto.
    expect(bccomp($this->converter->convert('1', $ml, $gota), '20', 8))->toBe(0);
});

it('no interpreta la notación científica como el número equivocado', function () {
    // El fallo concreto que `normalize()` evita: PHP interpola un float pequeño como '1.0E-5', y
    // `bcmath` lee esa cadena como **1**. Sin normalizar, una cantidad diminuta se convertiría en una
    // cien mil veces mayor, sin ningún error.
    $g = Unit::query()->where('code', 'g')->firstOrFail();
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();

    $tiny = 0.00001;

    expect((string) $tiny)->toContain('E');

    $result = $this->converter->convert($tiny, $g, $kg);

    // 0.00001 g son 0.00000001 kg. Si se hubiera leído como 1, daría 0.001.
    expect(bccomp($result, '0.00000001', 8))->toBe(0);
});

it('la unidad base del sistema se reconoce por su factor y no por una columna', function () {
    $g = Unit::query()->where('code', 'g')->firstOrFail();
    $kg = Unit::query()->where('code', 'kg')->firstOrFail();

    expect($g->isSystemBase())->toBeTrue();
    expect($kg->isSystemBase())->toBeFalse();
});

it('la base RECHAZA un factor de cero o negativo', function () {
    // Un factor de 0 haría que toda cantidad en esa unidad valiera cero, y un negativo produciría
    // cantidades negativas de insumo. Las dos cosas contaminarían el costeo sin error visible, así
    // que el candado es un CHECK en la base y no sólo una regla de validación.
    expect(fn () => Unit::factory()->create(['factor_to_base' => '0']))
        ->toThrow(QueryException::class);

    expect(fn () => Unit::factory()->create(['factor_to_base' => '-1']))
        ->toThrow(QueryException::class);
});

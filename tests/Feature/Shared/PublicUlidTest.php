<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\UlidFixture;

/**
 * El ULID público (ARQUITECTURA_MAESTRA §7) y su interacción con la colación.
 *
 * El riesgo real de estas pruebas no es el generador: es que la base tiene
 * colación acento- y caso-insensible (D58) y los identificadores se declaran
 * `ascii_bin`. Esa combinación exige normalizar lo que llega del cliente, y sin
 * prueba nadie recordaría por qué.
 */
beforeEach(function () {
    app(TenantContext::class)->set(1);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('asigna un ULID al crear, sin que nadie lo pase', function () {
    $row = UlidFixture::create(['name' => 'Sucursal Centro']);

    expect($row->ulid)->toHaveLength(26);
    expect($row->ulid)->toBe(Str::upper($row->ulid));
});

it('genera ULIDs monótonos crecientes', function () {
    // Propiedad que hace útil al ULID frente a un UUID v4: ordena por tiempo de
    // creación, así que un índice sobre él no fragmenta y "los últimos N" es un
    // recorrido de índice.
    expect(UlidFixture::newUlid())->toBeLessThan(UlidFixture::newUlid());
});

it('la llave de ruta es el ULID y no el id secuencial', function () {
    // Exponer el id autoincremental filtraría volumen de negocio y permitiría
    // enumerar recursos ajenos sumando uno.
    expect((new UlidFixture)->getRouteKeyName())->toBe('ulid');
});

it('la columna es sensible a mayúsculas: un ULID en minúsculas NO empata por sí solo', function () {
    $row = UlidFixture::create(['name' => 'Sucursal Norte']);

    // Esto es lo que `ascii_bin` compra y lo que hace obligatoria la
    // normalización: con la colación por defecto de la base, esta consulta
    // encontraría la fila y dos ULIDs que difieren sólo en capitalización serían
    // el mismo identificador.
    expect(UlidFixture::query()->where('ulid', Str::lower($row->ulid))->exists())->toBeFalse();
});

it('findByUlid normaliza y encuentra la fila con el ULID en minúsculas', function () {
    $row = UlidFixture::create(['name' => 'Sucursal Sur']);

    $encontrada = UlidFixture::findByUlid(Str::lower($row->ulid));

    expect($encontrada)->not->toBeNull();
    expect($encontrada->id)->toBe($row->id);
});

it('la búsqueda por ULID sigue acotada por tenant', function () {
    $ajena = app(TenantContext::class)->runFor(2, fn () => UlidFixture::create(['name' => 'de otro tenant']));

    // El identificador público no es una puerta trasera al aislamiento: conocer el
    // ULID de un recurso ajeno no lo hace visible (ADR-002).
    expect(UlidFixture::findByUlid($ajena->ulid))->toBeNull();
});

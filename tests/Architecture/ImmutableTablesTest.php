<?php

declare(strict_types=1);

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\ImmutableBuilder;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Tests\Support\DomainModelDiscovery;

/**
 * LAS TABLAS INMUTABLES DE §7 SON INMUTABLES DE VERDAD
 *
 * ARQUITECTURA_MAESTRA §7 declara una lista de tablas append-only: "sin UPDATE/DELETE, corrección por reversa o
 * nuevo registro". Hasta ahora cada una tenía su prueba de inmutabilidad, pero **nadie verificaba la lista
 * completa**: un modelo nuevo sobre una tabla que debería ser inmutable podía nacer sin el trait, y su prueba de
 * inmutabilidad simplemente no existiría — no hay nada que falle cuando una prueba no se escribe.
 *
 * Este candado invierte la carga: la lista vive aquí, y el modelo tiene que cumplirla.
 */

/**
 * Los modelos que la especificación declara inmutables, con la tabla de §7 a la que corresponden.
 *
 * El **kardex** entró al construirse en la Iteración 3, y el candado hizo su trabajo: la prueba falló al
 * aparecer un modelo con el trait que no estaba en esta lista, con el mensaje exacto de qué agregar y dónde.
 *
 * Falta el de la iteración que no existe: el diario financiero (5). Se agrega cuando se construya — y este
 * candado será entonces el recordatorio, porque su ausencia se nota al leer la lista.
 *
 * @var array<class-string, string>
 */
$declarados = [
    AuditEntry::class => 'bitácora de auditoría',
    TenantStatusTransition::class => 'historial de estados del tenant (D75)',
    ArticleCost::class => 'historial de costos',
    PriceChange::class => 'historial de precios',
    StockMovement::class => 'kardex',
];

it('los modelos declarados inmutables usan el trait que lo impone', function () use ($declarados) {
    $sinTrait = [];

    foreach ($declarados as $clase => $tabla) {
        if (! in_array(Immutable::class, class_uses_recursive($clase), strict: true)) {
            $sinTrait[] = "{$clase} ({$tabla})";
        }
    }

    expect($sinTrait)->toBe([], sprintf(
        "Estos modelos son inmutables según §7 y NO usan el trait `Immutable`:\n  - %s\n\n".
        'Sin él, `update()` y `delete()` funcionan y la corrección por reversa deja de ser la única vía.',
        implode("\n  - ", $sinTrait),
    ));
});

it('el trait cierra las TRES vías de escritura destructiva, no sólo la obvia', function () use ($declarados) {
    // La comprobación que importa: que el trait haga lo que promete. Se verifica sobre la clase y no sobre una
    // fila, porque lo que se prueba es la superficie, no un caso.
    //
    // La tercera vía —el query builder— es la más ancha y la más fácil de olvidar: `Model::query()->update()`
    // **no dispara eventos**, así que un trait que sólo escuchara `updating` la dejaría abierta.
    foreach (array_keys($declarados) as $clase) {
        $modelo = new $clase;

        expect($modelo->usesTimestamps())
            ->toBeFalse("{$clase}: una tabla append-only no tiene fecha de modificación.");

        // `newModelQuery()` y no `query()`: devuelve el mismo builder sin aplicar el scope global de tenant, así
        // que el candado no necesita contexto para comprobar una propiedad de la clase.
        expect($modelo->newModelQuery())
            ->toBeInstanceOf(
                ImmutableBuilder::class,
                "{$clase}: el query builder no es el inmutable, así que `query()->update()` pasaría."
            );
    }
});

it('ningún modelo usa el trait sin estar declarado en la lista', function () use ($declarados) {
    // La dirección inversa. Un modelo marcado inmutable que no esté en §7 es una de dos cosas: una tabla que
    // debería estar en la lista de la especificación, o un trait puesto por error que va a impedir correcciones
    // legítimas. Las dos merecen que alguien las mire.
    $conTrait = array_values(array_filter(
        DomainModelDiscovery::all(),
        fn (string $clase): bool => in_array(Immutable::class, class_uses_recursive($clase), strict: true),
    ));

    expect($conTrait)->not->toBeEmpty('El descubridor no encontró ningún modelo inmutable: no está leyendo nada.');

    $noDeclarados = array_diff($conTrait, array_keys($declarados));

    expect($noDeclarados)->toBe([], sprintf(
        "Estos modelos usan `Immutable` y no están en la lista de §7:\n  - %s\n\n".
        'Agrégalos aquí y a §7, o quita el trait si la tabla no debería ser append-only.',
        implode("\n  - ", $noDeclarados),
    ));
});

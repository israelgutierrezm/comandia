<?php

declare(strict_types=1);

use App\Modules\Costing\Domain\RecipeGraph;

/**
 * EL GRAFO DE COMPOSICIÓN — dominio puro, sin base de datos
 *
 * Se prueba aparte del detector porque aquí se pueden armar grafos que el sistema **jamás permitiría
 * guardar** —un ciclo— y grafos que serían tediosos de construir en la base. Es la única forma de
 * comprobar que el algoritmo ve lo que tiene que ver.
 *
 * Los números son identificadores de artículo cualesquiera.
 */
it('encuentra un camino directo', function () {
    // 1 usa 2.
    $graph = new RecipeGraph([1 => [2]]);

    expect($graph->findPath(1, 2))->toBe([1, 2]);
});

it('encuentra un camino indirecto', function () {
    // torta(1) → pan(2) → masa(3) → harina(4)
    $graph = new RecipeGraph([1 => [2], 2 => [3], 3 => [4]]);

    expect($graph->findPath(1, 4))->toBe([1, 2, 3, 4]);
});

it('devuelve null cuando no hay camino', function () {
    $graph = new RecipeGraph([1 => [2], 3 => [4]]);

    expect($graph->findPath(1, 4))->toBeNull();
});

it('un grafo en DIAMANTE no es un ciclo', function () {
    // A(1) usa B(2) y C(3); las dos usan D(4). Se llega a D por dos caminos y NO hay ciclo.
    //
    // Es el caso que un algoritmo con conjunto de visitados mal usado reportaría como ciclo, y es
    // completamente normal en cocina: el pan y la salsa usan los dos la misma sal.
    $graph = new RecipeGraph([1 => [2, 3], 2 => [4], 3 => [4]]);

    expect($graph->findPath(1, 1))->toBe([1]);   // trivial: uno mismo
    expect($graph->findPath(2, 1))->toBeNull();  // no se vuelve a A
    expect($graph->findPath(3, 1))->toBeNull();
    expect($graph->findPath(1, 4))->not->toBeNull();
});

it('devuelve el camino MÁS CORTO', function () {
    // De 1 a 4 hay dos rutas: 1→4 directa y 1→2→3→4. El recorrido en anchura devuelve la corta, que es
    // la más fácil de entender para quien tiene que arreglar la receta.
    $graph = new RecipeGraph([1 => [2, 4], 2 => [3], 3 => [4]]);

    expect($graph->findPath(1, 4))->toBe([1, 4]);
});

it('detecta un ciclo de dos y de tres saltos', function () {
    // Ciclos armados a mano: la base nunca contendrá uno porque el detector lo impide, así que ésta es
    // la única manera de comprobar que el algoritmo no se cuelga y encuentra el camino.
    $dos = new RecipeGraph([1 => [2], 2 => [1]]);

    expect($dos->findPath(2, 1))->toBe([2, 1]);

    $tres = new RecipeGraph([1 => [2], 2 => [3], 3 => [1]]);

    expect($tres->findPath(2, 1))->toBe([2, 3, 1]);
});

it('no se cuelga con un ciclo', function () {
    // La razón de ser del conjunto de visitados. Sin él, esto sería un bucle infinito — que es
    // exactamente lo que un ciclo guardado le haría al recálculo de costos.
    $graph = new RecipeGraph([1 => [2], 2 => [3], 3 => [1]]);

    expect($graph->findPath(1, 99))->toBeNull();
});

it('reemplazar las aristas de un artículo no toca las de los demás', function () {
    // Es lo que permite validar el estado POSTERIOR a guardar: la receta que se guarda reemplaza sus
    // líneas, y el resto del grafo se queda como está.
    $graph = (new RecipeGraph([1 => [2], 3 => [4]]))->replaceEdgesOf(1, [5]);

    expect($graph->findPath(1, 2))->toBeNull();
    expect($graph->findPath(1, 5))->toBe([1, 5]);
    expect($graph->findPath(3, 4))->toBe([3, 4]);
});

it('reemplazar deduplica componentes repetidos', function () {
    $graph = (new RecipeGraph)->replaceEdgesOf(1, [2, 2, 3]);

    expect($graph->findPath(1, 2))->toBe([1, 2]);
    expect($graph->findPath(1, 3))->toBe([1, 3]);
});

it('los dependientes son transitivos', function () {
    // harina(4) → masa(3) → pan(2) → torta(1). Cambiar el costo de la harina tiene que llegar a la torta:
    // es la dirección que recorrerá el recálculo del paso 7.
    $graph = new RecipeGraph([1 => [2], 2 => [3], 3 => [4]]);

    expect($graph->dependentsOf(4))->toEqualCanonicalizing([3, 2, 1]);
    expect($graph->dependentsOf(2))->toEqualCanonicalizing([1]);
    expect($graph->dependentsOf(1))->toBe([]);
});

it('los dependientes no se cuelgan con un ciclo', function () {
    $graph = new RecipeGraph([1 => [2], 2 => [3], 3 => [1]]);

    expect($graph->dependentsOf(1))->toEqualCanonicalizing([3, 2, 1]);
});

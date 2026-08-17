<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain;

/**
 * El grafo de composición de recetas: qué artículo usa a qué artículos.
 *
 * Estructura de datos pura, sin base de datos: se construye desde fuera y se pregunta. Separarlo así es
 * lo que permite probar la detección de ciclos con grafos armados a mano, incluidos los que serían
 * incómodos de crear en la base — un ciclo, que es justamente lo que el sistema impide guardar.
 *
 * Las aristas van del **dueño de la receta** al **componente**. Sólo participan recetas de artículos:
 * la receta de un modificador (paso 10) consume insumos pero nada la consume como ingrediente, así que
 * no puede formar parte de un ciclo.
 */
final class RecipeGraph
{
    /**
     * @param  array<int, list<int>>  $edges  artículo dueño => componentes que usa
     */
    public function __construct(private array $edges = []) {}

    /**
     * Reemplaza las aristas de un artículo.
     *
     * Es lo que permite validar el estado **posterior** a la escritura: guardar una receta reemplaza
     * sus líneas, así que preguntar sobre el grafo actual respondería sobre un grafo que ya no va a
     * existir.
     *
     * @param  list<int>  $components
     */
    public function replaceEdgesOf(int $articleId, array $components): self
    {
        $edges = $this->edges;
        $edges[$articleId] = array_values(array_unique($components));

        return new self($edges);
    }

    /**
     * ¿Se puede llegar de `$from` a `$to` siguiendo aristas? Devuelve el camino, o `null`.
     *
     * Recorrido en anchura con mapa de padres para poder reconstruir el camino. En anchura y no en
     * profundidad a propósito: devuelve el camino **más corto**, que es el más fácil de entender para
     * quien tiene que arreglar la receta.
     *
     * El conjunto de visitados no produce falsos positivos con grafos en diamante —A usa B y C, las dos
     * usan D— porque lo que se busca es alcanzabilidad y no un recorrido exhaustivo: llegar dos veces a
     * D no es un ciclo, y basta explorarlo una.
     *
     * @return list<int>|null camino de `$from` a `$to` inclusive
     */
    public function findPath(int $from, int $to): ?array
    {
        if ($from === $to) {
            return [$from];
        }

        /** @var array<int, int|null> $parents */
        $parents = [$from => null];
        $queue = [$from];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->edges[$current] ?? [] as $next) {
                if (array_key_exists($next, $parents)) {
                    continue;
                }

                $parents[$next] = $current;

                if ($next === $to) {
                    return $this->reconstruct($parents, $to);
                }

                $queue[] = $next;
            }
        }

        return null;
    }

    /**
     * Los artículos que usan a `$articleId`, directa o indirectamente.
     *
     * Es la dirección inversa, y la necesita el recálculo transitivo del paso 7: cuando cambia el costo
     * de la harina, esto responde "el pan y la torta". Se calcula recorriendo las aristas al revés en
     * lugar de mantener un segundo grafo, que podría desincronizarse.
     *
     * @return list<int>
     */
    public function dependentsOf(int $articleId): array
    {
        $reverse = [];

        foreach ($this->edges as $owner => $components) {
            foreach ($components as $component) {
                $reverse[$component][] = $owner;
            }
        }

        $found = [];
        $queue = [$articleId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($reverse[$current] ?? [] as $owner) {
                if (in_array($owner, $found, strict: true)) {
                    continue;
                }

                $found[] = $owner;
                $queue[] = $owner;
            }
        }

        return $found;
    }

    /**
     * @param  array<int, int|null>  $parents
     * @return list<int>
     */
    private function reconstruct(array $parents, int $to): array
    {
        $path = [$to];
        $current = $to;

        while (($parents[$current] ?? null) !== null) {
            $current = $parents[$current];
            $path[] = $current;
        }

        return array_reverse($path);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;

/**
 * A qué área de preparación le toca un artículo, en una sucursal (D240).
 *
 * ## El orden, y por qué
 *
 * 1. La regla del **artículo** en esa sucursal. Es el override: «las hamburguesas van a la parrilla, no a la cocina».
 * 2. La regla de su **categoría**, y si no hay, la de la categoría padre. Es el caso normal y el que hace la carga de
 *    datos soportable: «Bebidas → barra» son dos toques, no cuatrocientos.
 * 3. **Nada**, y es legítimo: un item sin área no se comanda. Una cerveza que el mesero saca de la nevera no necesita
 *    que la cocina haga nada.
 *
 * ## Se resuelve al CAPTURAR, no al comandar
 *
 * Podría resolverse al comandar y sería peor. El área es lo que decide por qué impresora sale el papel, y si se
 * resolviera al comandar, cambiar una regla de ruteo a media tarde partiría una cuenta abierta entre dos áreas: los
 * items capturados antes irían a un sitio y los de después a otro, para el mismo plato. Resuelta y **guardada** en la
 * línea, la cuenta es coherente consigo misma — es la misma razón por la que el precio se congela.
 *
 * ## La caché es por petición y no va a Redis
 *
 * Una orden de doce líneas consultaría la tabla veinticuatro veces sin ella. Con Redis habría que invalidarla al editar
 * una regla, y una caché mal invalidada aquí manda comandas a la impresora equivocada sin que nada falle — el peor tipo
 * de error. Por petición no hace falta invalidar nada: la siguiente petición ya lee la tabla.
 */
final class ResolveAreaRoute
{
    /** @var array<string, int|null> */
    private array $cache = [];

    public function forArticle(Article $article, int $branchId): ?int
    {
        $llave = $branchId.':'.$article->id;

        if (array_key_exists($llave, $this->cache)) {
            return $this->cache[$llave];
        }

        return $this->cache[$llave] = $this->resolve($article, $branchId);
    }

    private function resolve(Article $article, int $branchId): ?int
    {
        $porArticulo = PosAreaRoute::query()
            ->where('branch_id', $branchId)
            ->where('article_id', $article->id)
            ->value('preparation_area_id');

        if ($porArticulo !== null) {
            return (int) $porArticulo;
        }

        if ($article->category_id === null) {
            return null;
        }

        // La categoría del artículo y, si no tiene regla, la de su padre. `article_categories` tiene exactamente dos
        // niveles —lo garantiza un CHECK de la Iteración 2— así que el ascenso es un salto y no un recorrido. Con N
        // niveles esto sería un `while`, y conviene que quede escrito: el día que el árbol crezca, este método es el que
        // hay que tocar.
        $categorias = [$article->category_id];

        $padre = $article->category?->parent_id;

        if ($padre !== null) {
            $categorias[] = $padre;
        }

        $reglas = PosAreaRoute::query()
            ->where('branch_id', $branchId)
            ->whereIn('article_category_id', $categorias)
            ->pluck('preparation_area_id', 'article_category_id');

        foreach ($categorias as $categoriaId) {
            if ($reglas->has($categoriaId)) {
                return (int) $reglas[$categoriaId];
            }
        }

        return null;
    }
}

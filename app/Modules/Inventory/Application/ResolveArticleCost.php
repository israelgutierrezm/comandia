<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;

/**
 * El costo con el que inventarios valúa un movimiento: **último costo** (D152).
 *
 * Existe como servicio propio y no como método privado de quien lo usa porque lo usan dos: el registro del kardex
 * —para congelar el costo de cada movimiento— y el de mermas —para saber si la pérdida cruza el umbral antes de
 * tocar existencia.
 *
 * Tenerlo en un solo sitio es lo que garantiza que las dos respuestas coincidan. Con la consulta duplicada, el día
 * que la valuación cambie —promedio ponderado, por ejemplo— una de las dos copias se quedaría atrás y el umbral se
 * evaluaría con un costo distinto del que después se congela en el movimiento.
 *
 * Es también donde se ve la dependencia declarada `Inventory → Costing` (D160): se lee el costo, nunca se escribe.
 */
final class ResolveArticleCost
{
    /**
     * El costo vigente del artículo, o `null` si no tiene ninguno capturado.
     *
     * Se lee de la proyección `article_current_costs` y no del historial: es exactamente para lo que existe (D94),
     * y sumar el historial en cada movimiento de inventario sería sumar dos tablas grandes a la vez.
     *
     * **`null` es un resultado legítimo y no se sustituye por cero.** Un artículo sin costo capturado tiene
     * movimientos sin valor, y eso es información. Un cero diría que la mercancía es gratis, y de ahí saldría un
     * valor de inventario falso que nadie sospecharía — y una merma que nunca cruza su umbral.
     *
     * @return numeric-string|null
     */
    public function current(Article $article): ?string
    {
        $cost = ArticleCurrentCost::query()->where('article_id', $article->id)->value('unit_cost');

        return is_string($cost) ? $cost : null;
    }

    /**
     * Lo mismo para muchos artículos de un golpe, indexado por `article_id`.
     *
     * Existe por el conteo físico: abrir el conteo de un almacén congela el costo de cada renglón, y un almacén de
     * doscientos artículos produciría doscientas consultas idénticas. Los artículos que no aparecen en el
     * resultado son los que no tienen costo — la ausencia significa `null`, igual que en `current()`.
     *
     * @param  list<int>  $articleIds
     * @return array<int, numeric-string>
     */
    public function currentForMany(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        /** @var array<int, numeric-string> $costs */
        $costs = ArticleCurrentCost::query()
            ->whereIn('article_id', $articleIds)
            ->pluck('unit_cost', 'article_id')
            ->all();

        return $costs;
    }
}

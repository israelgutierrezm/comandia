<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Application\CalculateArticleCost;
use App\Modules\Costing\Domain\CostBreakdownLine;
use Illuminate\Http\JsonResponse;

/**
 * El desglose del costo de un artículo, paso por paso.
 *
 * Existe porque **un costo sin desglose es un número que nadie cree**. Es la pantalla que convence al
 * dueño de que el sistema no se equivocó, y la que contesta "¿por qué subió el costo de las enchiladas?"
 * sin abrir la base de datos.
 *
 * Se calcula al leer y no se almacena: es una vista del catálogo en este instante, y guardarla sería crear
 * una tercera fuente —además del historial y la proyección— que quedaría desfasada.
 */
final class CostBreakdownController
{
    public function __invoke(Article $article, CalculateArticleCost $calculator): JsonResponse
    {
        $breakdown = $calculator->breakdown($article);

        return new JsonResponse([
            'data' => [
                'article_ulid' => $breakdown->articleUlid,
                'article_name' => $breakdown->articleName,

                // `null` significa "no se puede calcular", no cero. La diferencia importa: cero diría que
                // producirlo es gratis, y de ahí saldría un margen del 100 %.
                'unit_cost' => $breakdown->unitCost,
                'is_computable' => $breakdown->isComputable(),

                // Qué falta para poder calcularlo. Es lo accionable de la respuesta: sin esta lista, un
                // "no calculable" deja al usuario buscando a mano cuál de treinta insumos no tiene costo.
                'missing_costs' => $breakdown->missingCosts,

                // El costo de producir una tanda completa, y lo que rinde esa tanda. Los dos hacen falta
                // para entender de dónde sale el costo unitario: 100 pesos de salsa que rinden 2 L.
                'batch_cost' => $breakdown->total,
                'batch_yield_in_base_unit' => $breakdown->outputQuantityInBaseUnit,

                'lines' => array_map($this->line(), $breakdown->lines),
            ],
        ]);
    }

    /**
     * @return callable(CostBreakdownLine): array<string, mixed>
     */
    private function line(): callable
    {
        return function (CostBreakdownLine $line): array {
            return [
                'component_ulid' => $line->componentUlid,
                'component_name' => $line->componentName,

                // Como se capturó...
                'quantity' => $line->quantity,
                'unit_code' => $line->unitCode,

                // ...y ya convertido. Los dos, porque el paso de conversión es justo lo que alguien quiere
                // revisar cuando el número no le cuadra; presentar sólo el resultado obligaría a rehacer la
                // conversión a mano para verificarla.
                'quantity_in_base_unit' => $line->quantityInComponentBaseUnit,
                'base_unit_code' => $line->componentBaseUnitCode,

                'component_unit_cost' => $line->componentUnitCost,

                // D21: el rendimiento DIVIDE. Viaja explícito aunque casi siempre sea 100, porque es lo que
                // explica por qué el costo de la línea no es cantidad × costo a secas.
                'yield_percent' => $line->yieldPercent,

                'line_cost' => $line->lineCost,

                // Si el componente es producible, su propio desglose viene anidado: es la cascada abierta,
                // y es lo que permite bajar del platillo al pan y del pan a la harina en una sola respuesta.
                'is_producible' => $line->componentIsProducible,
                'sub_lines' => array_map($this->line(), $line->subLines),
            ];
        };
    }
}

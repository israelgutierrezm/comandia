<?php

declare(strict_types=1);

namespace App\Modules\Costing\Application;

use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Shared\Domain\Contracts\ProductCostProbe;

/**
 * El costo vigente de un artículo, leído de la proyección de `Costing` (D95).
 *
 * `Costing` responde esta pregunta que le hace el POS por el kernel (D322). Lee `article_current_costs` —la proyección de
 * costo vigente, no el historial— porque el POS necesita el costo de AHORA, y la proyección existe justo para no recorrer
 * el historial en cada lectura. El tenant lo impone el global scope; se devuelve un primitivo.
 *
 * Si el artículo no tiene costo vigente (nunca comprado ni costeado), devuelve `"0"`: un artículo puede venderse sin haber
 * costado nunca, y su margen quedará sin costo hasta que lo tenga.
 */
final readonly class CostingProductCostProbe implements ProductCostProbe
{
    public function currentUnitCost(int $articleId): string
    {
        $cost = ArticleCurrentCost::query()
            ->where('article_id', $articleId)
            ->value('unit_cost');

        return $cost === null ? '0' : (string) $cost;
    }
}

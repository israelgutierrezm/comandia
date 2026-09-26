<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Alta/edición del mapeo de menú de un marketplace (ADR-015, Fase 2): un id de ítem en la plataforma
 * apunta a UN artículo, por canal. Es un upsert por (canal, id externo): volver a mapear el mismo ítem
 * cambia su artículo, no duplica la fila —la ingesta necesita una sola respuesta por ítem—.
 *
 * El artículo debe ser **vendible**: un ítem de marketplace es algo que se vende. Mapearlo a un insumo
 * haría que un pedido real descontara y cobrara algo que no está en venta.
 */
final class ManageMarketplaceMenuMap
{
    public function save(string $channel, string $externalItemId, Article $article): MarketplaceMenuMap
    {
        if (! $article->is_sellable) {
            throw new UnprocessableEntityHttpException(
                "«{$article->displayName()}» no es vendible: un ítem de marketplace debe apuntar a algo que se vende.",
            );
        }

        $map = MarketplaceMenuMap::query()
            ->where('channel', $channel)
            ->where('external_item_id', $externalItemId)
            ->first()
            ?? new MarketplaceMenuMap(['channel' => $channel, 'external_item_id' => $externalItemId]);

        $map->article_id = $article->id;
        $map->save();

        return $map->refresh();
    }
}

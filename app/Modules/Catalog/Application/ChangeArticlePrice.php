<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Catalog\Events\ArticlePriceChanged;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Support\Facades\DB;

/**
 * Fija el precio de un artículo y lo historiza (D15).
 *
 * Único camino para cambiar `articles.base_price`. Que sea único es lo que permite afirmar que **no existe un
 * precio cambiado sin historial**: el precio y su fila de historia se escriben en la misma transacción.
 *
 * ## El snapshot de costeo llega como DATO, no como dependencia
 *
 * El historial guarda el costo, el markup y el precio sugerido del momento. Los tres son de `Costing`, y
 * `Catalog` **no puede depender de `Costing`** (P1) — el candado de fronteras lo rechazaría, y declararlo
 * crearía un ciclo el mismo día.
 *
 * Así que este servicio los **recibe**. Los calcula quien llama, que es el controlador de `Costing`: ese
 * módulo sí puede depender de `Catalog`, así que la dependencia fluye en el único sentido permitido. El
 * precio sigue siendo dato maestro del catálogo y su dueño sigue siendo este módulo.
 *
 * ## Dos capas de auditoría, no una
 *
 * `price_changes` es el histórico de **dominio**: inmutable, para siempre, con el estado del costeo. La
 * bitácora **técnica** registra además la acción con IP y terminal, porque §6.7 lista los precios entre lo
 * que vigila. Son complementarias: la primera contesta "cómo evolucionó este precio", la segunda "quién
 * tocó qué desde dónde".
 */
final readonly class ChangeArticlePrice
{
    public function __construct(
        private AuditLogger $audit,
        private ContextHolder $context,
    ) {}

    /**
     * @param  numeric-string  $newPrice  con IVA incluido (D30)
     * @param  numeric-string|null  $suggestedPrice  lo que el sistema sugería en ese momento
     * @param  numeric-string|null  $unitCost  el costo con el que lo sugería
     * @param  numeric-string|null  $markupPercent  el markup aplicado — NO el margen (D13)
     */
    public function change(
        Article $article,
        string $newPrice,
        ?string $suggestedPrice = null,
        ?string $unitCost = null,
        ?string $markupPercent = null,
        ?string $reason = null,
    ): PriceChange {
        $previous = $article->base_price;

        $change = DB::transaction(function () use (
            $article, $newPrice, $previous, $suggestedPrice, $unitCost, $markupPercent, $reason,
        ): PriceChange {
            // El historial primero: si algo falla después, la transacción revierte las dos escrituras. El
            // orden no cambia el resultado, pero deja claro que no hay camino en el que el precio quede
            // cambiado sin su fila.
            $change = PriceChange::create([
                'article_id' => $article->id,
                // NULL = precio maestro. El override por sucursal llega en el paso 9 y usará esta misma
                // tabla con `branch_id`.
                'branch_id' => null,
                'previous_price' => $previous,
                'new_price' => $newPrice,
                'suggested_price' => $suggestedPrice,
                'unit_cost_at_change' => $unitCost,
                'markup_percent' => $markupPercent,
                'reason' => $reason,
                'actor_membership_id' => $this->context->getOrNull()?->membership?->id,
            ]);

            $article->update(['base_price' => $newPrice]);

            return $change;
        });

        $this->audit->log(
            action: AuditAction::PRICE_CHANGED,
            auditable: $article,
            before: ['base_price' => $previous],
            after: ['base_price' => $newPrice, 'reason' => $reason],
        );

        ArticlePriceChanged::dispatch($change);

        return $change;
    }
}

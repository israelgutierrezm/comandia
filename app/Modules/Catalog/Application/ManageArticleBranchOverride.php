<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Catalog\Events\ArticlePriceChanged;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleBranchOverride;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Support\Facades\DB;

/**
 * Overrides de precio y disponibilidad por sucursal (§6.1).
 *
 * ## El precio por sucursal se historiza igual que el maestro
 *
 * `price_changes` tiene `branch_id` desde el paso 8 precisamente para esto: la pregunta "¿por qué esta
 * sucursal cobra $95 y las demás $85?" merece la misma respuesta que la del precio maestro, con actor, motivo
 * y el estado del costeo del momento.
 *
 * El snapshot de costeo llega como **dato**, por lo mismo que en `ChangeArticlePrice`: `Catalog` no puede
 * depender de `Costing` (P1), así que lo calcula quien llama — el controlador de `Costing`, que sí puede
 * depender de este módulo (D115).
 *
 * ## Una fila que hereda todo se borra
 *
 * Quitar el precio propio de una sucursal que no tenía override de disponibilidad deja una fila con las dos
 * columnas en NULL, y eso es indistinguible de no tener override. Conservarla dejaría la pregunta "¿esta
 * sucursal tiene precio propio?" con dos respuestas para el mismo estado.
 */
final readonly class ManageArticleBranchOverride
{
    public function __construct(
        private AuditLogger $audit,
        private ContextHolder $context,
    ) {}

    /**
     * Fija el precio propio de una sucursal y lo historiza.
     *
     * @param  numeric-string  $price
     * @param  numeric-string|null  $suggestedPrice
     * @param  numeric-string|null  $unitCost
     * @param  numeric-string|null  $markupPercent
     */
    public function setPrice(
        Article $article,
        Branch $branch,
        string $price,
        ?string $suggestedPrice = null,
        ?string $unitCost = null,
        ?string $markupPercent = null,
        ?string $reason = null,
    ): PriceChange {
        $override = $this->existing($article, $branch);

        // El precio anterior de ESTA sucursal, que puede ser el heredado si no tenía override. Se registra el
        // heredado y no NULL: "antes cobraba lo mismo que el negocio" es información, y un NULL diría que no
        // tenía precio.
        $previous = $override?->price ?? $article->base_price;

        $change = DB::transaction(function () use (
            $article, $branch, $price, $previous, $suggestedPrice, $unitCost, $markupPercent, $reason,
        ): PriceChange {
            $change = PriceChange::create([
                'article_id' => $article->id,
                'branch_id' => $branch->id,
                'previous_price' => $previous,
                'new_price' => $price,
                'suggested_price' => $suggestedPrice,
                'unit_cost_at_change' => $unitCost,
                'markup_percent' => $markupPercent,
                'reason' => $reason,
                'actor_membership_id' => $this->context->getOrNull()?->membership?->id,
            ]);

            ArticleBranchOverride::query()->updateOrCreate(
                ['article_id' => $article->id, 'branch_id' => $branch->id],
                ['price' => $price],
            );

            return $change;
        });

        $this->audit->log(
            action: AuditAction::PRICE_CHANGED,
            auditable: $article,
            before: ['branch_price' => $previous, 'branch_id' => $branch->id],
            after: ['branch_price' => $price, 'branch_id' => $branch->id, 'reason' => $reason],
        );

        ArticlePriceChanged::dispatch($change);

        return $change;
    }

    /**
     * Quita el precio propio: la sucursal vuelve a heredar el del negocio.
     *
     * Se historiza como cualquier otro cambio, con el precio maestro como valor nuevo — porque eso es lo que
     * la sucursal va a cobrar desde ahora, y el historial tiene que poder explicar por qué bajó.
     */
    public function clearPrice(
        Article $article,
        Branch $branch,
        ?string $reason = null,
    ): ?PriceChange {
        $override = $this->existing($article, $branch);

        if ($override === null || $override->price === null) {
            // No había precio propio: no hay nada que quitar y no se inventa una fila de historial de un
            // cambio que no ocurrió.
            return null;
        }

        $previous = $override->price;
        $inherited = $article->base_price;

        $change = DB::transaction(function () use ($article, $branch, $override, $previous, $inherited, $reason): ?PriceChange {
            $change = $inherited === null
                ? null
                : PriceChange::create([
                    'article_id' => $article->id,
                    'branch_id' => $branch->id,
                    'previous_price' => $previous,
                    'new_price' => $inherited,
                    'reason' => $reason ?? 'Vuelve al precio del negocio',
                    'actor_membership_id' => $this->context->getOrNull()?->membership?->id,
                ]);

            $override->update(['price' => null]);
            $this->discardIfEmpty($override);

            return $change;
        });

        $this->audit->log(
            action: AuditAction::PRICE_CHANGED,
            auditable: $article,
            before: ['branch_price' => $previous, 'branch_id' => $branch->id],
            after: ['branch_price' => null, 'branch_id' => $branch->id, 'inherits' => $inherited],
        );

        return $change;
    }

    /**
     * Fija o quita la disponibilidad propia de una sucursal.
     *
     * `null` = volver a heredar. **No se historiza en `price_changes`**: no es un precio, y meterlo ahí
     * ensuciaría el historial que D15 define. La bitácora técnica sí lo registra, y eso basta: apagar un
     * platillo en una sucursal es reversible y no afecta a ningún documento pasado.
     */
    public function setAvailability(
        Article $article,
        Branch $branch,
        ?bool $isAvailable,
    ): ?ArticleBranchOverride {
        $override = ArticleBranchOverride::query()->updateOrCreate(
            ['article_id' => $article->id, 'branch_id' => $branch->id],
            ['is_available_in_pos' => $isAvailable],
        );

        if ($this->discardIfEmpty($override)) {
            return null;
        }

        return $override->refresh();
    }

    private function existing(Article $article, Branch $branch): ?ArticleBranchOverride
    {
        return ArticleBranchOverride::query()
            ->where('article_id', $article->id)
            ->where('branch_id', $branch->id)
            ->first();
    }

    /**
     * @return bool si la fila se borró
     */
    private function discardIfEmpty(ArticleBranchOverride $override): bool
    {
        if (! $override->refresh()->overridesNothing()) {
            return false;
        }

        $override->delete();

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Application\ManageArticleBranchOverride;
use App\Modules\Catalog\Http\Controllers\ArticleBranchOverrideController;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Application\SuggestPrice;
use App\Modules\Costing\Http\Requests\ChangeArticlePriceRequest;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;

/**
 * Precio propio de una sucursal (§6.1).
 *
 * Vive en `Costing` por lo mismo que el precio maestro (D115): historizarlo exige el snapshot de costeo
 * —costo, markup y sugerido del momento— y `Catalog` no puede depender de `Costing` (P1). La escritura la sigue
 * haciendo `Catalog\Application\ManageArticleBranchOverride`: el precio, maestro o por sucursal, es dato del
 * catálogo.
 *
 * El **costo no cambia por sucursal en v1**, así que el sugerido es el mismo en todas; lo que cambia es el
 * precio contra el que se compara. Por eso el margen y el semáforo se calculan con el precio de ESTA sucursal
 * y no con el maestro: es lo que esa sucursal está cobrando de verdad.
 */
final class ArticleBranchPriceController
{
    public function __construct(private readonly ManageArticleBranchOverride $overrides) {}

    /**
     * Fija el precio propio de la sucursal y lo historiza con `branch_id`.
     */
    public function update(
        ChangeArticlePriceRequest $request,
        Article $article,
        Branch $branch,
        SuggestPrice $suggest,
        ContextHolder $context,
    ): JsonResponse {
        // La sucursal tiene que estar en el alcance de quien opera: el `tenant_id` protege del negocio ajeno,
        // no de la sucursal ajena dentro del propio. La regla se comparte con el controlador de `Catalog` para
        // que no existan dos copias que se desincronicen.
        ArticleBranchOverrideController::assertBranchInScope($branch, $context);

        $price = $request->string('price')->toString();

        // El snapshot se toma ANTES de escribir: es el estado del costeo con el que se tomó la decisión.
        $suggestion = $suggest->for($article);

        $this->overrides->setPrice(
            article: $article,
            branch: $branch,
            price: $price,
            suggestedPrice: $suggestion->suggestedPrice,
            unitCost: $suggestion->unitCost,
            markupPercent: $suggestion->markupPercent,
            reason: $request->filled('reason') ? $request->string('reason')->toString() : null,
        );

        return new JsonResponse(['data' => $this->present($article, $branch, $suggest, $price)]);
    }

    /**
     * Quita el precio propio: la sucursal vuelve a cobrar el del negocio.
     */
    public function destroy(
        Article $article,
        Branch $branch,
        SuggestPrice $suggest,
        ContextHolder $context,
    ): JsonResponse {
        ArticleBranchOverrideController::assertBranchInScope($branch, $context);

        $this->overrides->clearPrice($article, $branch);

        return new JsonResponse([
            'data' => $this->present($article, $branch, $suggest, $article->base_price),
        ]);
    }

    /**
     * @param  numeric-string|null  $effectivePrice
     * @return array<string, mixed>
     */
    private function present(
        Article $article,
        Branch $branch,
        SuggestPrice $suggest,
        ?string $effectivePrice,
    ): array {
        // El margen y el semáforo, contra el precio de ESTA sucursal.
        $suggestion = $suggest->for($article, $effectivePrice);

        $override = $article->branchOverrides()
            ->where('branch_id', $branch->id)
            ->first();

        return [
            'article_ulid' => $article->ulid,
            'branch_ulid' => $branch->ulid,

            'master_price' => $article->base_price,

            // `null` = esta sucursal hereda. Distinguirlo de un valor propio igual al maestro importa: el día
            // que cambie el precio del negocio, lo que hereda lo sigue y lo que tiene override no.
            'branch_price' => $override?->price,
            'price_is_overridden' => $override?->price !== null,

            'effective_price' => $effectivePrice,

            'suggested_price' => $suggestion->suggestedPrice,
            'unit_cost' => $suggestion->unitCost,
            'markup_percent' => $suggestion->markupPercent,
            'margin_percent' => $suggestion->marginPercent,
            'deviation_percent' => $suggestion->deviationPercent,
            'is_stale' => $suggestion->isStale,
        ];
    }
}

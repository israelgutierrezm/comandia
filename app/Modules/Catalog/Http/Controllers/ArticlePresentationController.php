<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreArticlePresentationRequest;
use App\Modules\Catalog\Http\Resources\ArticlePresentationResource;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Presentaciones de compra de un artículo (D22).
 *
 * Existen ya en esta iteración, y no en la 3 donde están las compras, porque **la captura manual de
 * costo las necesita**: "compré un costal de 25 kg en $600" es como piensa el dueño, y pedirle que
 * divida a mano es pedirle justo el cálculo donde se equivoca — con la particularidad de que un costo
 * unitario mal capturado contamina el costeo de todo lo que use ese insumo.
 */
final class ArticlePresentationController
{
    /**
     * @return AnonymousResourceCollection<Collection<int, ArticlePurchasePresentation>>
     */
    public function index(Article $article): AnonymousResourceCollection
    {
        $presentations = $article->purchasePresentations()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return ArticlePresentationResource::collection($presentations);
    }

    public function store(StoreArticlePresentationRequest $request, Article $article): JsonResponse
    {
        $presentation = DB::transaction(function () use ($request, $article): ArticlePurchasePresentation {
            $presentation = $article->purchasePresentations()->create($request->validated());

            $this->enforceSingleDefault($article, $presentation);

            return $presentation;
        });

        return (new ArticlePresentationResource($presentation->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        StoreArticlePresentationRequest $request,
        Article $article,
        ArticlePurchasePresentation $presentation,
    ): ArticlePresentationResource {
        DB::transaction(function () use ($request, $article, $presentation): void {
            $presentation->update($request->validated());

            $this->enforceSingleDefault($article, $presentation);
        });

        return new ArticlePresentationResource($presentation->refresh());
    }

    public function archive(
        Article $article,
        ArticlePurchasePresentation $presentation,
    ): ArticlePresentationResource {
        // Baja y no borrado: en la Iteración 3 habrá recepciones de compra apuntando a esta
        // presentación, y el costo que produjo ya está en el historial.
        $presentation->update(['status' => 'inactive', 'is_default' => false]);

        return new ArticlePresentationResource($presentation->refresh());
    }

    /**
     * Una sola presentación por omisión por artículo.
     *
     * No hay índice único que lo imponga —sería un único parcial, que MySQL no tiene— así que lo
     * impone el servicio. Dos presentaciones marcadas por omisión harían que "la presentación
     * habitual" fuera ambigua, y la captura de costo elegiría una de las dos sin criterio.
     */
    private function enforceSingleDefault(Article $article, ArticlePurchasePresentation $presentation): void
    {
        if (! $presentation->is_default) {
            return;
        }

        $article->purchasePresentations()
            ->whereKeyNot($presentation->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}

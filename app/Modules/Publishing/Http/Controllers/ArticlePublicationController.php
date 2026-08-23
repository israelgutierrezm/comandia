<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Publishing\Application\ManageArticlePublication;
use App\Modules\Publishing\Http\Requests\SaveArticlePublicationRequest;
use App\Modules\Publishing\Http\Requests\UploadArticleImageRequest;
use App\Modules\Publishing\Http\Resources\ArticlePublicationResource;
use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use Illuminate\Http\JsonResponse;

/**
 * La capa de publicación de un artículo (Iteración 8, Tanda A). La editan quienes administran menús o tienda
 * (`publishing.articles.manage`, un solo permiso para las dos superficies). El artículo viene del Core (`Catalog`);
 * `Publishing` lo enriquece sin duplicarlo.
 */
final class ArticlePublicationController
{
    public function __construct(private readonly ManageArticlePublication $publications) {}

    public function show(Article $article): JsonResponse
    {
        $publication = $this->publications->publicationFor($article);

        return new JsonResponse(['data' => new ArticlePublicationResource($publication->load('images'))]);
    }

    public function update(SaveArticlePublicationRequest $request, Article $article): JsonResponse
    {
        $publication = $this->publications->save($article, $request->validated());

        return new JsonResponse(['data' => new ArticlePublicationResource($publication->load('images'))]);
    }

    public function uploadImage(UploadArticleImageRequest $request, Article $article): JsonResponse
    {
        $this->publications->addImage($article, $request->file('image'), $request->input('alt_text'));

        $publication = $this->publications->publicationFor($article);

        return new JsonResponse(['data' => new ArticlePublicationResource($publication->load('images'))], 201);
    }

    public function destroyImage(ArticleImage $articleImage): JsonResponse
    {
        $this->publications->removeImage($articleImage);

        return new JsonResponse(status: 204);
    }
}

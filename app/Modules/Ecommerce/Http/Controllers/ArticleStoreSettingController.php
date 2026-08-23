<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Application\ManageArticleStoreSetting;
use App\Modules\Ecommerce\Http\Requests\SaveArticleStoreSettingRequest;
use App\Modules\Ecommerce\Http\Resources\ArticleStoreSettingResource;
use Illuminate\Http\JsonResponse;

/**
 * Ajustes de tienda de un artículo (Iteración 8, Tanda B). Gateado por `module:Ecommerce` y `ecommerce.store.configure`.
 * La descripción y las fotos NO se editan aquí —son de `Publishing`, compartidas con el menú—; aquí sólo lo exclusivo de
 * la tienda: política de stock, visibilidad, SEO y precio por canal.
 */
final class ArticleStoreSettingController
{
    public function __construct(private readonly ManageArticleStoreSetting $settings) {}

    public function show(Article $article): JsonResponse
    {
        return new JsonResponse(['data' => new ArticleStoreSettingResource($this->settings->settingsFor($article))]);
    }

    public function update(SaveArticleStoreSettingRequest $request, Article $article): JsonResponse
    {
        $setting = $this->settings->save($article, $request->validated());

        return new JsonResponse(['data' => new ArticleStoreSettingResource($setting)]);
    }
}

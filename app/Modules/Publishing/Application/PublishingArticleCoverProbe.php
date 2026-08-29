<?php

declare(strict_types=1);

namespace App\Modules\Publishing\Application;

use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use App\Modules\Shared\Domain\Contracts\ArticleCoverProbe;

/**
 * Resuelve {@see ArticleCoverProbe} con la galería de `Publishing`: la portada de un artículo es su primera foto por
 * `sort_order`. El scope de tenant de `ArticleImage` acota la consulta al negocio en contexto.
 */
final class PublishingArticleCoverProbe implements ArticleCoverProbe
{
    public function coversFor(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        $covers = [];

        ArticleImage::query()
            ->whereIn('article_id', $articleIds)
            ->orderBy('article_id')
            ->orderBy('sort_order')
            ->get()
            ->each(function (ArticleImage $image) use (&$covers): void {
                // La primera por artículo (menor `sort_order`) es la portada; las siguientes se ignoran.
                $covers[$image->article_id] ??= $image->url();
            });

        return $covers;
    }
}

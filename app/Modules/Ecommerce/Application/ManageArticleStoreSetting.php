<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;

/**
 * Administra los ajustes de tienda de un artículo (Iteración 8, Tanda B): política de stock, visibilidad en tienda, SEO y
 * precio por canal. `Ecommerce` enriquece el artículo del Core; `Catalog` no conoce estos ajustes.
 */
final class ManageArticleStoreSetting
{
    public function settingsFor(Article $article): ArticleStoreSetting
    {
        // Por omisión: no está en tienda y, si se pone, «vender siempre» (no bloquea por stock hasta que se elija otra
        // política). Se pasan explícitos para que el modelo recién creado los refleje en memoria.
        return ArticleStoreSetting::query()->firstOrCreate(
            ['article_id' => $article->id],
            ['stock_policy' => 'sell_always', 'is_in_store' => false],
        );
    }

    /**
     * @param  array{stock_policy?: string, is_in_store?: bool, seo_title?: string|null, seo_description?: string|null, channel_price?: string|null}  $data
     */
    public function save(Article $article, array $data): ArticleStoreSetting
    {
        $setting = $this->settingsFor($article);
        $setting->fill($data);
        $setting->save();

        // `refresh()`: `channel_price` es DECIMAL(12,2); releer garantiza que el Resource publique lo almacenado
        // (`160.00`), no lo asignado (`160`).
        return $setting->refresh();
    }
}

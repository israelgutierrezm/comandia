<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use App\Modules\Publishing\Infrastructure\Models\ArticlePublication;
use App\Modules\Shared\Domain\Contracts\StockAvailabilityProbe;

/**
 * Arma el catálogo de la tienda para una sucursal (Iteración 8, Tanda B).
 *
 * Reúne tres fuentes sin que ninguna conozca a las otras: el artículo y su precio de sucursal del Core (`Catalog`), la
 * descripción y fotos de `Publishing`, y los ajustes de tienda de `Ecommerce`. El precio es el **de canal** si está, o el
 * de la sucursal (D331). La tienda **respeta stock** (ADR-007) según la política del artículo, preguntando por la sonda
 * `StockAvailabilityProbe` (el POS, en cambio, nunca se bloquea).
 */
final class AssembleStore
{
    public function __construct(private readonly StockAvailabilityProbe $stock) {}

    /**
     * @return list<array{name: string, items: list<array{ulid: string, name: string, description: string|null, price: string|null, image: string|null, out_of_stock: bool}>}>
     */
    public function catalogFor(Store $store, int $branchId): array
    {
        $settings = ArticleStoreSetting::query()->where('is_in_store', true)->get()->keyBy('article_id');

        if ($settings->isEmpty()) {
            return [];
        }

        $publications = ArticlePublication::query()->get()->keyBy('article_id');
        $coverByArticle = ArticleImage::query()->orderBy('sort_order')->get()->groupBy('article_id');
        $categories = ArticleCategory::query()->get()->keyBy('id');

        $articles = Article::query()
            ->where('is_sellable', true)
            ->whereIn('id', $settings->keys())
            ->with(['branchOverrides'])
            ->get();

        /** @var array<int, array{name: string, sort: int, items: list<array<string, mixed>>}> $sections */
        $sections = [];

        foreach ($articles as $article) {
            /** @var ArticleStoreSetting $setting */
            $setting = $settings->get($article->id);

            $hasStock = $this->stock->hasStock((int) $article->id, $branchId);
            $outOfStock = false;

            // La tienda SÍ respeta stock, según la política del artículo.
            if (! $hasStock) {
                if ($setting->stock_policy === 'hide') {
                    continue; // no aparece
                }
                if ($setting->stock_policy === 'mark_out_of_stock') {
                    $outOfStock = true; // aparece marcado «agotado»
                }
                // sell_always: se vende igual.
            }

            $topCategory = $this->topCategory($categories, $article->category_id);

            if ($topCategory === null) {
                continue;
            }

            // Precio: el de canal si está; si no, el efectivo de la sucursal (override de sucursal → base).
            $price = $setting->channel_price ?? $article->effectivePricingFor($branchId)->price;

            /** @var ArticlePublication|null $publication */
            $publication = $publications->get($article->id);
            $cover = $coverByArticle->get($article->id)?->first();

            $sections[$topCategory->id] ??= [
                'name' => $topCategory->name,
                'sort' => $topCategory->sort_order ?? 0,
                'items' => [],
            ];

            $sections[$topCategory->id]['items'][] = [
                'ulid' => $article->ulid,
                'name' => $article->displayName(),
                'description' => $publication?->long_description,
                'price' => $price,
                'image' => $cover?->url(),
                'out_of_stock' => $outOfStock,
                'sort' => $publication?->sort_order ?? 0,
            ];
        }

        uasort($sections, fn ($a, $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));

        $out = [];

        foreach ($sections as $section) {
            usort($section['items'], fn ($a, $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));

            $out[] = [
                'name' => $section['name'],
                'items' => array_map(fn (array $i): array => [
                    'ulid' => $i['ulid'],
                    'name' => $i['name'],
                    'description' => $i['description'],
                    'price' => $i['price'],
                    'image' => $i['image'],
                    'out_of_stock' => $i['out_of_stock'],
                ], $section['items']),
            ];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ArticleCategory>  $categories
     */
    private function topCategory(\Illuminate\Support\Collection $categories, ?int $categoryId): ?ArticleCategory
    {
        if ($categoryId === null) {
            return null;
        }

        $category = $categories->get($categoryId);

        if ($category === null) {
            return null;
        }

        return $category->parent_id === null ? $category : $categories->get($category->parent_id);
    }
}

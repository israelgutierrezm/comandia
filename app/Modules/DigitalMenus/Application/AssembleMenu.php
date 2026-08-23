<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use App\Modules\Publishing\Infrastructure\Models\ArticlePublication;

/**
 * Arma la estructura de un menú para pintarlo (público) o generarlo en PDF (Iteración 8, Tanda A).
 *
 * Lee los artículos del Core y su precio/disponibilidad **efectivos en la sucursal** del menú (`effectivePricingFor`, la
 * misma cascada del POS) y los enriquece con la capa de `Publishing` (descripción, foto, orden). No cura artículo por
 * artículo (v1): muestra los vendibles y **disponibles** de la sucursal, agrupados por su categoría de nivel 1, ocultando
 * los que la publicación marca invisibles. `Catalog` y `Publishing` se consumen como fuentes del Core; ninguno conoce a
 * `DigitalMenus`.
 */
final class AssembleMenu
{
    /**
     * @return array{
     *   name: string,
     *   show_prices: bool,
     *   theme: array{primary: string, font: string|null},
     *   sections: list<array{name: string, items: list<array{name: string, description: string|null, price: string|null, image: string|null}>}>
     * }
     */
    public function forMenu(DigitalMenu $menu): array
    {
        $branchId = (int) $menu->branch_id;

        // Publicación por artículo (descripción, orden, visibilidad) y galería, indexadas por article_id.
        $publications = ArticlePublication::query()->get()->keyBy('article_id');
        $coverByArticle = ArticleImage::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('article_id');

        // Categorías, para agrupar por la de nivel 1 (el «padre» de todo artículo).
        $categories = ArticleCategory::query()->get()->keyBy('id');

        $articles = Article::query()
            ->where('is_sellable', true)
            ->with(['branchOverrides'])
            ->get();

        /** @var array<int, array{name: string, sort: int, items: list<array{name: string, description: string|null, price: string|null, image: string|null, sort: int}>}> $sections */
        $sections = [];

        foreach ($articles as $article) {
            $pricing = $article->effectivePricingFor($branchId);

            // La regla del menú es la disponibilidad de la SUCURSAL (a diferencia del POS, que nunca se bloquea).
            if (! $pricing->isAvailableInPos) {
                continue;
            }

            /** @var ArticlePublication|null $publication */
            $publication = $publications->get($article->id);

            // Un artículo cuya publicación se marcó invisible no sale; sin publicación, sale por omisión.
            if ($publication !== null && $publication->is_visible === false) {
                continue;
            }

            $topCategory = $this->topCategory($categories, $article->category_id);

            if ($topCategory === null) {
                continue; // un vendible sin categoría no se puede agrupar; el catálogo ya exige categoría a los vendibles
            }

            $cover = $coverByArticle->get($article->id)?->first();

            $sections[$topCategory->id] ??= [
                'name' => $topCategory->name,
                'sort' => $topCategory->sort_order ?? 0,
                'items' => [],
            ];

            $sections[$topCategory->id]['items'][] = [
                'name' => $article->displayName(),
                'description' => $publication?->long_description,
                'price' => $menu->show_prices ? $pricing->price : null,
                'image' => $cover?->url(),
                'sort' => $publication?->sort_order ?? 0,
            ];
        }

        // Orden determinista: secciones por su orden, items por su orden de publicación y luego por nombre.
        uasort($sections, fn ($a, $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));

        $out = [];

        foreach ($sections as $section) {
            usort($section['items'], fn ($a, $b): int => $a['sort'] <=> $b['sort'] ?: strcmp($a['name'], $b['name']));

            $out[] = [
                'name' => $section['name'],
                'items' => array_map(fn (array $i): array => [
                    'name' => $i['name'],
                    'description' => $i['description'],
                    'price' => $i['price'],
                    'image' => $i['image'],
                ], $section['items']),
            ];
        }

        return [
            'name' => $menu->branch->name,
            'show_prices' => $menu->show_prices,
            'theme' => ['primary' => $menu->theme_primary, 'font' => $menu->theme_font],
            'sections' => $out,
        ];
    }

    /**
     * La categoría de nivel 1 de un artículo: si su categoría es hija, sube al padre (D18 acota a dos niveles).
     *
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

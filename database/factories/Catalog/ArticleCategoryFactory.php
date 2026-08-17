<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ArticleCategory>
 */
final class ArticleCategoryFactory extends Factory
{
    protected $model = ArticleCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Nombre único: el índice único es (tenant, parent_key, name) sobre una columna generada,
            // y dos categorías raíz con el mismo nombre chocarían — que es justo lo que ese índice
            // debe conseguir, pero no lo que una factory quiere provocar.
            'name' => 'Categoría '.Str::random(6),
            'parent_id' => null,
            'level' => 1,
            'sort_order' => 0,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * Subcategoría de la categoría indicada.
     *
     * Recibe el padre ya creado en lugar de crearlo aquí: el CHECK de la tabla exige que `level` y
     * `parent_id` cuenten la misma historia, y calcular el nivel sin ver al padre invita a que se
     * contradigan.
     */
    public function childOf(ArticleCategory $parent): self
    {
        return $this->state([
            'parent_id' => $parent->id,
            'level' => ArticleCategory::levelFor($parent),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories\Costing;

use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Infrastructure\Models\RecipeLine;
use Database\Factories\Catalog\ArticleFactory;
use Database\Factories\Catalog\UnitFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeLine>
 *
 * Igual que {@see RecipeFactory}: escribe la arista cruda sin validar. Es lo que permite armar un ciclo
 * en la base y comprobar que el detector lo encuentra — algo imposible por la vía normal, que es el punto.
 */
final class RecipeLineFactory extends Factory
{
    protected $model = RecipeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => fn (): RecipeFactory => RecipeFactory::new(),
            'component_article_id' => fn (): ArticleFactory => ArticleFactory::new(),
            'quantity' => '100.0000',
            'unit_id' => fn (): int|UnitFactory => Unit::query()->where('code', 'g')->value('id')
                ?? UnitFactory::new()->gram(),
            'yield_percent' => '100.00',
            'sort_order' => 0,
        ];
    }

    /**
     * @param  numeric-string  $percent
     */
    public function withYield(string $percent): self
    {
        return $this->state(['yield_percent' => $percent]);
    }
}

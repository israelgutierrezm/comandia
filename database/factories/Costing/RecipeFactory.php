<?php

declare(strict_types=1);

namespace Database\Factories\Costing;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use Database\Factories\Catalog\ArticleFactory;
use Database\Factories\Catalog\UnitFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 *
 * Crea la cabecera cruda, **sin pasar por `SaveRecipe`**: no valida invariantes ni detecta ciclos. Es
 * deliberado — las pruebas de la detección de ciclos necesitan poder construir grafos que el servicio
 * jamás permitiría guardar, y ésa es la única forma de comprobar que el detector los ve.
 */
final class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Producible, porque una receta en un artículo que no se produce es un costo que nadie usaría.
            'article_id' => fn (): ArticleFactory => ArticleFactory::new()->producible(),

            'output_quantity' => '1.0000',
            'output_unit_id' => fn (): int|Factory => Unit::query()->where('code', 'g')->value('id')
                ?? UnitFactory::new()->gram(),
            'notes' => null,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * @param  numeric-string  $quantity
     */
    public function yielding(string $quantity): self
    {
        return $this->state(['output_quantity' => $quantity]);
    }
}

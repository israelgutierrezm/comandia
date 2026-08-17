<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\ArticleStatus;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 *
 * El estado por defecto es un **insumo**: es la combinación de capacidades que no exige ni precio ni
 * categoría, así que sirve para cualquier prueba que sólo necesite "un artículo". Los estados
 * `sellable()` y `producible()` cargan lo que cada capacidad exige.
 */
final class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => null,
            'name' => 'Artículo '.fake()->unique()->numerify('####'),
            'short_name' => null,
            'category_id' => null,

            // REUTILIZA el gramo del tenant si ya existe.
            //
            // El alta de un negocio siembra las unidades por evento de dominio, así que crear otra
            // con código `g` viola el índice único (tenant, code) — y el error que sale, un choque de
            // llave en `units`, no dice nada sobre la causa. Pasó en cuatro pruebas de golpe.
            //
            // Reutilizar es además lo realista: un negocio tiene UNA unidad "gramo".
            'base_unit_id' => fn (): int|UnitFactory => Unit::query()->where('code', 'g')->value('id')
                ?? UnitFactory::new()->gram(),
            'is_sellable' => false,
            'is_inventoriable' => true,
            'is_supply' => true,
            'is_producible' => false,
            'base_price' => null,
            'markup_percent' => null,
            'is_available_in_pos' => true,
            'status' => ArticleStatus::Active,
        ];
    }

    /**
     * Vendible: exige precio y categoría (invariante I2 y P11), así que los pone.
     *
     * Si no los pusiera, el propio modelo lanzaría excepción al guardar — que es lo correcto, pero
     * convertiría cada prueba que necesita un artículo vendible en una prueba de invariantes.
     */
    public function sellable(?string $price = '100.00'): self
    {
        return $this->state([
            'is_sellable' => true,
            'is_inventoriable' => false,
            'is_supply' => false,
            'base_price' => $price,
            'category_id' => fn (): ArticleCategoryFactory => ArticleCategoryFactory::new(),
        ]);
    }

    /**
     * Producible: tiene receta propia y su costo se calcula en cascada (D16).
     */
    public function producible(): self
    {
        return $this->state([
            'is_producible' => true,
            'is_supply' => true,
        ]);
    }

    public function archived(): self
    {
        return $this->state([
            'status' => ArticleStatus::Archived,
            'is_available_in_pos' => false,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArticlePurchasePresentation>
 */
final class ArticlePurchasePresentationFactory extends Factory
{
    protected $model = ArticlePurchasePresentation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => fn (): ArticleFactory => ArticleFactory::new(),
            'name' => 'Caja con 12',
            'quantity_in_base_unit' => '12.0000',
            'barcode' => null,
            'is_default' => false,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * @param  numeric-string  $quantity
     */
    public function yielding(string $quantity, string $name = 'Presentación'): self
    {
        return $this->state([
            'name' => $name,
            'quantity_in_base_unit' => $quantity,
        ]);
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}

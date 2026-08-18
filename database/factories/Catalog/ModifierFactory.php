<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Modifier>
 */
final class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modifier_group_id' => fn (): ModifierGroupFactory => ModifierGroupFactory::new(),

            // Único por grupo.
            'name' => 'Opción '.Str::random(8),

            // Cero por omisión: «sin cebolla» es un modificador válido que no cuesta.
            'extra_price' => '0.00',
            'sort_order' => 0,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * @param  numeric-string  $price
     */
    public function costing(string $price): self
    {
        return $this->state(['extra_price' => $price]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}

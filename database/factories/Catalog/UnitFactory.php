<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Código aleatorio en minúsculas: el índice único es (tenant, code) en `ascii_bin`, así
            // que dos factories seguidas no pueden chocar por diferencia de caja.
            'code' => Str::lower(Str::random(6)),
            'name' => 'Unidad '.fake()->word(),
            'dimension' => UnitDimension::Mass,
            'factor_to_base' => '1',
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * Gramo: la unidad base del sistema para masa.
     */
    public function gram(): self
    {
        return $this->state([
            'code' => 'g',
            'name' => 'Gramo',
            'dimension' => UnitDimension::Mass,
            'factor_to_base' => '1',
        ]);
    }

    public function kilogram(): self
    {
        return $this->state([
            'code' => 'kg',
            'name' => 'Kilogramo',
            'dimension' => UnitDimension::Mass,
            'factor_to_base' => '1000',
        ]);
    }

    public function milliliter(): self
    {
        return $this->state([
            'code' => 'ml',
            'name' => 'Mililitro',
            'dimension' => UnitDimension::Volume,
            'factor_to_base' => '1',
        ]);
    }

    public function liter(): self
    {
        return $this->state([
            'code' => 'l',
            'name' => 'Litro',
            'dimension' => UnitDimension::Volume,
            'factor_to_base' => '1000',
        ]);
    }

    public function piece(): self
    {
        return $this->state([
            'code' => 'pza',
            'name' => 'Pieza',
            'dimension' => UnitDimension::Count,
            'factor_to_base' => '1',
        ]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}

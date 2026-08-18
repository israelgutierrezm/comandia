<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModifierGroup>
 */
final class ModifierGroupFactory extends Factory
{
    protected $model = ModifierGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Único por tenant, así que aleatorio para que dos factories seguidas no choquen.
            'name' => 'Grupo '.Str::random(8),

            // Opcional y sin límite por omisión: es la combinación que no restringe nada, así que sirve para
            // cualquier prueba que sólo necesite "un grupo".
            'is_required' => false,
            'min_selections' => 0,
            'max_selections' => null,
            'allows_quantity' => false,
            'status' => CatalogStatus::Active,
        ];
    }

    /**
     * Obligatorio: exige al menos una selección, porque un grupo obligatorio con mínimo 0 no obliga a nada y
     * el CHECK de la base lo rechaza.
     */
    public function required(int $min = 1, ?int $max = 1): self
    {
        return $this->state([
            'is_required' => true,
            'min_selections' => $min,
            'max_selections' => $max,
        ]);
    }

    /** Los "3 shots" de D7. */
    public function withQuantities(): self
    {
        return $this->state(['allows_quantity' => true]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => CatalogStatus::Inactive]);
    }
}

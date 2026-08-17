<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PreparationArea>
 */
final class PreparationAreaFactory extends Factory
{
    protected $model = PreparationArea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(6)),
            'name' => fake()->randomElement(['Cocina', 'Barra', 'Parrilla', 'Postres']),
            'status' => OperationalStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['status' => OperationalStatus::Inactive]);
    }
}

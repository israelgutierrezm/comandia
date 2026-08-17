<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // El código es único por tenant y entra en el folio, así que se genera
            // aleatorio en lugar de secuencial: dos factories del mismo test no deben
            // colisionar.
            'code' => Str::upper(Str::random(4)),
            'name' => 'Sucursal '.fake()->city(),
            'status' => OperationalStatus::Active,
            'timezone' => 'America/Mexico_City',
            'street' => fake()->streetName(),
            'exterior_number' => (string) fake()->numberBetween(1, 999),
            'neighborhood' => fake()->citySuffix(),
            'municipality' => fake()->city(),
            'state' => 'Jalisco',
            'postal_code' => fake()->numerify('#####'),
            'country' => 'MX',
            'phone' => fake()->numerify('33########'),
        ];
    }

    public function inactive(): self
    {
        return $this->state(['status' => OperationalStatus::Inactive]);
    }

    /**
     * Sucursal en otra zona horaria: el caso que rompe los cortes "del día" si
     * alguien asume una sola zona (§7).
     */
    public function inTimezone(string $timezone): self
    {
        return $this->state(['timezone' => $timezone]);
    }
}

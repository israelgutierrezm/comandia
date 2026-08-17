<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeProfile>
 */
final class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legal_first_name' => fake()->firstName(),
            'legal_paternal_surname' => fake()->lastName(),
            'legal_maternal_surname' => fake()->lastName(),
            'is_foreigner' => false,
            // Mayúsculas siempre: las columnas son `ascii_bin` y la normalización es
            // parte del contrato, no un detalle de presentación.
            'curp' => Str::upper(Str::random(18)),
            'rfc' => Str::upper(Str::random(13)),
            'nss' => fake()->numerify('###########'),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-18 years'),
            'hired_at' => fake()->dateTimeBetween('-5 years', 'now'),
        ];
    }

    /**
     * Persona extranjera: la CURP acepta ausencia y no hay apellido materno (§4.1).
     */
    public function foreigner(): self
    {
        return $this->state([
            'is_foreigner' => true,
            'curp' => null,
            'legal_maternal_surname' => null,
        ]);
    }

    public function terminated(): self
    {
        return $this->state(['terminated_at' => now()]);
    }
}

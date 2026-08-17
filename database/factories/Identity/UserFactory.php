<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'paternal_surname' => fake()->lastName(),
            'maternal_surname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'is_super_admin' => false,
        ];
    }

    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function superAdmin(): self
    {
        return $this->state(['is_super_admin' => true]);
    }

    /**
     * Persona extranjera: sin apellido materno (§4.1).
     */
    public function foreigner(): self
    {
        return $this->state(['maternal_surname' => null]);
    }
}

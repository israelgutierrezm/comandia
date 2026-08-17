<?php

declare(strict_types=1);

namespace Database\Factories\Tenancy;

use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' S.A. de C.V.',
            // Sufijo aleatorio: el slug es único en todo el SaaS y dos negocios
            // pueden llamarse igual.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'status' => TenantStatus::Active,
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->numerify('55########'),
            'onboarded_at' => now(),
        ];
    }

    public function pendingActivation(): self
    {
        return $this->state(['status' => TenantStatus::PendingActivation, 'onboarded_at' => null]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }

    public function readOnly(): self
    {
        return $this->state(['status' => TenantStatus::ReadOnly]);
    }
}

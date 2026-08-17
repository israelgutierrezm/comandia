<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Terminal>
 */
final class TerminalFactory extends Factory
{
    protected $model = Terminal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => fn (): BranchFactory => BranchFactory::new(),
            'code' => Str::upper(Str::random(6)),
            'name' => 'Caja '.fake()->numberBetween(1, 9),
            'status' => OperationalStatus::Active,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['status' => OperationalStatus::Inactive]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Warehouse>
 */
final class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Por defecto es almacén de sucursal, así que necesita sucursal: el CHECK
            // de la base lo exige y dejarlo en NULL haría fallar la inserción.
            'branch_id' => fn (): BranchFactory => BranchFactory::new(),
            'kind' => WarehouseKind::Branch,
            'code' => Str::upper(Str::random(6)),
            'name' => 'Almacén '.fake()->word(),
            'status' => OperationalStatus::Active,
        ];
    }

    /**
     * Almacén central: sin sucursal, surte a todas (D11).
     *
     * `branch_id` a NULL explícitamente, porque el CHECK de la base lo exige y
     * dejarlo puesto por error haría fallar la inserción — que es exactamente lo
     * que ese CHECK debe conseguir.
     */
    public function central(): self
    {
        return $this->state([
            'kind' => WarehouseKind::Central,
            'branch_id' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(['status' => OperationalStatus::Inactive]);
    }
}

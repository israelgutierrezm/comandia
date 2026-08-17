<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantMembership>
 */
final class TenantMembershipFactory extends Factory
{
    protected $model = TenantMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => MembershipStatus::Active,
            'employee_code' => Str::upper(Str::random(6)),
            'has_all_branches' => false,
        ];
    }

    /**
     * Membresía SIN credenciales de acceso: el lavaloza en nómina que jamás inicia
     * sesión (§4.1).
     *
     * OJO: por el invariante I1 (D66) esta membresía necesita perfil de empleado, o
     * no tendrá nombre. Los tests que la usen deben crearlo.
     */
    public function withoutCredentials(): self
    {
        return $this->state(['user_id' => null]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => MembershipStatus::Suspended]);
    }

    public function invited(): self
    {
        return $this->state(['status' => MembershipStatus::Invited]);
    }

    /**
     * Alcance sobre todas las sucursales, presentes y futuras.
     */
    public function allBranches(): self
    {
        return $this->state(['has_all_branches' => true]);
    }

    /**
     * Con PIN de terminal. El hash se calcula igual que en producción, no se
     * falsifica: un test que verifique el flujo de PIN tiene que ejercitar la
     * comparación real.
     */
    public function withPin(string $pin = '1234'): self
    {
        return $this->state([
            'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(),
            'pin_failed_attempts' => 0,
        ]);
    }

    public function pinLocked(): self
    {
        return $this->state([
            'pin_hash' => Hash::make('1234'),
            'pin_set_at' => now(),
            'pin_failed_attempts' => 5,
            'pin_locked_until' => now()->addMinutes(15),
        ]);
    }
}

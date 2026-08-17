<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\TenantLimits;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Alta de personal: usuario global, membresía y —cuando toca— perfil de empleado.
 *
 * Concentra tres reglas que ningún controlador debería reimplementar:
 *
 * 1. **El invariante I1 (D66).** Una membresía sin credenciales de acceso necesita perfil de
 *    empleado, porque el perfil es su única fuente de nombre. Aquí se crean en la MISMA
 *    transacción: no existe camino que produzca una y no la otra, y por eso el invariante no
 *    depende de que nadie se acuerde.
 *
 * 2. **El correo es único en todo el SaaS.** Una persona con dos restaurantes tiene un solo
 *    usuario global (§4.1), así que si el correo ya existe se reutiliza el usuario en lugar de
 *    fallar. Lo que sí falla es intentar darla de alta dos veces en el MISMO tenant.
 *
 * 3. **El límite de usuarios se verifica con uso medido** (D4), no con un contador.
 */
final readonly class CreateMembership
{
    public function __construct(
        private TenantContext $context,
        private TenantLimits $limits,
    ) {}

    /**
     * @param  list<string>  $roleUlids
     * @param  list<string>  $branchUlids
     * @param  array<string, mixed>|null  $employeeProfile  obligatorio si no hay credenciales
     */
    public function create(
        ?string $email,
        ?string $plainPassword,
        string $firstName,
        string $paternalSurname,
        ?string $maternalSurname,
        ?string $employeeCode,
        array $roleUlids = [],
        array $branchUlids = [],
        bool $hasAllBranches = false,
        ?array $employeeProfile = null,
    ): TenantMembership {
        if (! $this->limits->allows(TenantLimitKey::MaxUsers)) {
            throw new ConflictHttpException(sprintf(
                'Alcanzaste el límite de %d usuario(s) de tu plan. Da de baja a alguien o '
                .'contacta a soporte para ampliarlo.',
                (int) $this->limits->limit(TenantLimitKey::MaxUsers),
            ));
        }

        $sinCredenciales = $email === null;

        if ($sinCredenciales && $employeeProfile === null) {
            // Invariante I1: sin usuario y sin perfil sería una persona sin nombre — una comanda
            // sin mesero identificable y una fila de auditoría que no dice quién actuó.
            throw new ConflictHttpException(
                'Una persona sin credenciales de acceso necesita perfil de empleado: es de donde '
                .'sale su nombre (D66).'
            );
        }

        return DB::transaction(function () use (
            $email, $plainPassword, $firstName, $paternalSurname, $maternalSurname,
            $employeeCode, $roleUlids, $branchUlids, $hasAllBranches, $employeeProfile,
        ): TenantMembership {
            $user = null;

            if ($email !== null) {
                $user = User::query()->where('email', $email)->first();

                if ($user === null) {
                    $user = User::create([
                        'first_name' => $firstName,
                        'paternal_surname' => $paternalSurname,
                        'maternal_surname' => $maternalSurname,
                        'email' => $email,
                        'password' => $plainPassword,
                    ]);
                }

                $yaPertenece = TenantMembership::query()
                    ->where('user_id', $user->id)
                    ->exists();

                if ($yaPertenece) {
                    throw new ConflictHttpException('Esa persona ya forma parte de este negocio.');
                }
            }

            $membership = TenantMembership::create([
                'user_id' => $user?->id,
                'employee_code' => $employeeCode,
                // Con credenciales nace INVITADA: la persona todavía no ha entrado ni fijado
                // nada. Sin credenciales nace activa, porque no hay nada que aceptar — existe
                // para nómina y para aparecer en reportes, no para iniciar sesión.
                'status' => $user === null ? MembershipStatus::Active : MembershipStatus::Invited,
                'has_all_branches' => $hasAllBranches,
            ]);

            if ($employeeProfile !== null) {
                EmployeeProfile::create($employeeProfile + ['membership_id' => $membership->id]);
            }

            $this->syncBranchScopes($membership, $branchUlids);

            if ($user !== null && $roleUlids !== []) {
                $roles = Role::query()->whereIn('ulid', $roleUlids)->get();

                $user->syncRoles($roles);

                // El rol por defecto es el primero indicado: el cliente manda la lista en el
                // orden en que quiere que se apliquen, y el primero es el que el operador verá
                // activo al entrar.
                $membership->update(['default_role_id' => $roles->first()?->id]);
            }

            return $membership->refresh();
        });
    }

    /**
     * @param  list<string>  $branchUlids
     */
    public function syncBranchScopes(TenantMembership $membership, array $branchUlids): void
    {
        $membership->branchScopes()->delete();

        if ($branchUlids === []) {
            return;
        }

        $branchIds = Branch::query()->whereIn('ulid', $branchUlids)->pluck('id');

        foreach ($branchIds as $branchId) {
            $membership->branchScopes()->create(['branch_id' => $branchId]);
        }
    }
}

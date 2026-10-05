<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\TenantLimits;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Alta de personal: membresía, perfil de empleado y —si va a entrar al sistema— su invitación.
 *
 * Concentra tres reglas que ningún controlador debería reimplementar:
 *
 * 1. **El invariante I1 (D66).** Quien no tiene cuenta toma su nombre del perfil de empleado. Aquí se crean en la MISMA
 *    transacción: no existe camino que produzca una membresía sin nombre.
 *
 * 2. **El acceso se da invitando** (diseño de acceso, fase 3). Antes el alta pedía la contraseña de la persona y la
 *    tecleaba quien la daba de alta; y si el correo ya tenía cuenta en otro negocio, la sumaba a éste sin preguntarle.
 *    Ahora nace INVITADA y sin cuenta: la crea —o liga la suya— al aceptar desde el enlace que le llega. Mientras tanto
 *    su nombre vive en el perfil, que se arma con el del alta si no se capturó uno.
 *
 * 3. **El límite de usuarios se verifica con uso medido** (D4), no con un contador.
 */
final readonly class CreateMembership
{
    public function __construct(
        private TenantContext $context,
        private TenantLimits $limits,
        private MembershipInvitations $invitations,
        private ContextHolder $holder,
    ) {}

    /**
     * @param  list<string>  $roleUlids
     * @param  list<string>  $branchUlids
     * @param  array<string, mixed>|null  $employeeProfile  obligatorio si no hay correo
     * @param  TenantMembership|null  $invitedBy  quien invita; por omisión, la persona de la petición
     */
    public function create(
        ?string $email,
        string $firstName,
        string $paternalSurname,
        ?string $maternalSurname,
        ?string $employeeCode,
        array $roleUlids = [],
        array $branchUlids = [],
        bool $hasAllBranches = false,
        ?array $employeeProfile = null,
        ?TenantMembership $invitedBy = null,
    ): CreatedMembership {
        if (! $this->limits->allows(TenantLimitKey::MaxUsers)) {
            throw new ConflictHttpException(sprintf(
                'Alcanzaste el límite de %d usuario(s) de tu plan. Da de baja a alguien o '
                .'contacta a soporte para ampliarlo.',
                (int) $this->limits->limit(TenantLimitKey::MaxUsers),
            ));
        }

        $conAcceso = $email !== null;

        if (! $conAcceso && $employeeProfile === null) {
            // Invariante I1: sin cuenta y sin perfil sería una persona sin nombre — una comanda
            // sin mesero identificable y una fila de auditoría que no dice quién actuó.
            throw new ConflictHttpException(
                'Una persona sin credenciales de acceso necesita perfil de empleado: es de donde '
                .'sale su nombre (D66).'
            );
        }

        $invitador = $invitedBy ?? $this->holder->getOrNull()?->membership;

        if ($conAcceso && $invitador === null) {
            throw new ConflictHttpException('Para invitar a alguien hace falta saber quién invita.');
        }

        return DB::transaction(function () use (
            $email, $firstName, $paternalSurname, $maternalSurname, $employeeCode,
            $roleUlids, $branchUlids, $hasAllBranches, $employeeProfile, $invitador,
        ): CreatedMembership {
            $membership = TenantMembership::create([
                // Sin cuenta todavía: con acceso, la liga la persona al aceptar su invitación.
                'user_id' => null,
                'employee_code' => $employeeCode,
                // Con acceso nace INVITADA: todavía no ha aceptado. Sin acceso nace activa, porque no
                // hay nada que aceptar — existe para nómina, para su PIN y para aparecer en reportes.
                'status' => $email !== null ? MembershipStatus::Invited : MembershipStatus::Active,
                'has_all_branches' => $hasAllBranches,
            ]);

            // El nombre de quien todavía no tiene cuenta vive en su perfil (D66). Si no se capturó uno, se arma con el
            // nombre del alta: es el nombre con el que el negocio la conoce.
            EmployeeProfile::create(($employeeProfile ?? [
                'legal_first_name' => $firstName,
                'legal_paternal_surname' => $paternalSurname,
                'legal_maternal_surname' => $maternalSurname,
            ]) + ['membership_id' => $membership->id]);

            $this->syncBranchScopes($membership, $branchUlids);

            if ($roleUlids !== []) {
                // El rol por defecto es el primero indicado: el cliente manda la lista en el orden en que quiere que se
                // apliquen, y el primero es el que la persona verá activo al entrar. Los roles en sí viven en la cuenta,
                // que todavía no existe: viajan en la invitación y se asignan al aceptar.
                $membership->update([
                    'default_role_id' => Role::query()->where('ulid', $roleUlids[0])->value('id'),
                ]);
            }

            $invitacion = $email === null
                ? null
                : $this->invitations->invite($membership, $email, $roleUlids, $invitador);

            return new CreatedMembership($membership->refresh(), $invitacion);
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

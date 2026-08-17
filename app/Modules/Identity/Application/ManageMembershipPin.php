<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Asignación y retiro del PIN de terminal (D54, D84).
 *
 * El PIN vive en la membresía y no en el usuario: el PIN de un tenant no es el PIN de otro
 * (§4.1). Un mesero que trabaja en dos restaurantes tiene dos PIN, y comprometer uno no
 * compromete el otro.
 *
 * ## El PIN nunca se devuelve, ni una vez
 *
 * Se guarda hasheado y no hay forma de recuperarlo. Quien lo olvida recibe uno nuevo. Devolver
 * el PIN "sólo al crearlo" parecería cómodo y significaría que existe en claro en un log de
 * respuesta HTTP, en la memoria del navegador y en el historial de la herramienta con que se
 * probó la API.
 */
final readonly class ManageMembershipPin
{
    public function __construct(private AuditLogger $audit) {}

    public function set(TenantMembership $membership, string $pin): void
    {
        if (! $membership->hasCredentials()) {
            // El PIN autoriza acciones y la autorización se evalúa sobre los roles, que sólo
            // existen si hay usuario. Un PIN en una membresía sin credenciales no podría
            // autorizar nada: darlo sería prometer una capacidad que no existe.
            throw new ConflictHttpException(
                'Una persona sin credenciales de acceso no puede tener PIN: el PIN autoriza '
                .'acciones y la autorización se evalúa sobre sus roles.'
            );
        }

        if ($membership->employee_code === null) {
            // Con D84 el autorizador se identifica con código de empleado + PIN. Sin código, el
            // PIN sería inutilizable — y descubrirlo con el cliente delante es peor que no
            // poder asignarlo.
            throw new ConflictHttpException(
                'Asigna primero un código de empleado: la autorización por PIN identifica a la '
                .'persona por su código (D84).'
            );
        }

        $membership->forceFill([
            'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(),
            // Un PIN nuevo limpia el bloqueo: si el gerente lo restableció es porque la persona
            // legítima lo necesita, y dejarla bloqueada quince minutos más no protege de nada.
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        $this->audit->log(
            action: AuditAction::PIN_RESET,
            auditable: $membership,
            // Jamás el PIN ni su hash en la bitácora: sólo que se fijó y cuándo.
            after: ['pin_set' => true],
        );
    }

    public function remove(TenantMembership $membership): void
    {
        $membership->forceFill([
            'pin_hash' => null,
            'pin_set_at' => null,
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        $this->audit->log(
            action: AuditAction::PIN_RESET,
            auditable: $membership,
            after: ['pin_set' => false],
        );
    }

    /**
     * Desbloqueo sin cambiar el PIN.
     *
     * Existe aparte porque son dos intenciones distintas: "olvidó su PIN" y "se equivocó cinco
     * veces con prisa". Forzar a cambiarlo en el segundo caso obligaría a que el gerente conozca
     * el PIN nuevo de otra persona.
     */
    public function unlock(TenantMembership $membership): void
    {
        $membership->forceFill([
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        $this->audit->log(
            action: AuditAction::PIN_RESET,
            auditable: $membership,
            after: ['unlocked' => true],
        );
    }
}

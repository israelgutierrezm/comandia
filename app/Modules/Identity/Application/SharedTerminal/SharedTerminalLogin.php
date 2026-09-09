<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\SharedTerminal;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationFailed;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Identificación del OPERADOR de una terminal compartida por código de empleado + PIN (ADR-012).
 *
 * ## En qué se parece y en qué NO a la autorización por PIN (ADR-008)
 *
 * Comparte la mecánica de PIN de D84: búsqueda por código de empleado (índice único, una sola
 * comparación de hash) y **el mismo contador de bloqueo** (`pin_failed_attempts` / `pin_locked_until`,
 * `security.pin_max_attempts` / `security.pin_lock_minutes`), de modo que un PIN bloqueado lo está
 * para ambos usos. La lógica de bloqueo se replica a propósito aquí en vez de refactorizar
 * `PinAuthorizationService` —que es `readonly`, es el único lugar de la excepción de ADR-008 y tiene
 * su candado estructural—: preferimos duplicar quince líneas verificables antes que desestabilizar
 * ese servicio.
 *
 * La diferencia sustantiva: esto **no evalúa permisos** (eso lo hace la autorización por acción) ni
 * la unión de roles. Sólo identifica a quién opera; sus permisos salen luego de su **rol activo**
 * (D9) en el contexto que arma el middleware. Y comprueba que pueda operar en la sucursal de la
 * terminal, porque el operador de una caja física está, por definición, en esa sucursal.
 */
final readonly class SharedTerminalLogin
{
    public function __construct(
        private Settings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws PinAuthorizationFailed
     */
    public function authenticate(string $employeeCode, string $pin, int $branchId): TenantMembership
    {
        $membership = TenantMembership::query()
            ->where('employee_code', Str::upper($employeeCode))
            ->where('status', MembershipStatus::Active->value)
            ->first();

        // Empleado inexistente/inactivo/sin PIN da el MISMO error que un PIN incorrecto: distinguirlos
        // permitiría enumerar códigos de empleado válidos (mismo criterio que ADR-008).
        if ($membership === null || ! $membership->hasPin()) {
            throw PinAuthorizationFailed::invalid();
        }

        if ($membership->isPinLocked()) {
            throw PinAuthorizationFailed::locked(
                (int) ceil(now()->diffInMinutes($membership->pin_locked_until, absolute: true)),
            );
        }

        if (! Hash::check($pin, (string) $membership->pin_hash)) {
            $this->registerFailedAttempt($membership);

            throw PinAuthorizationFailed::invalid();
        }

        // El PIN es correcto. ¿Puede operar en la sucursal de esta terminal? Si no, no cuenta como
        // fallo de PIN (el PIN no falló), pero no puede tomar esta caja.
        if (! $membership->canOperateInBranch($branchId)) {
            throw PinAuthorizationFailed::notAuthorized();
        }

        $this->resetFailedAttempts($membership);

        return $membership;
    }

    private function registerFailedAttempt(TenantMembership $membership): void
    {
        $maxAttempts = (int) $this->settings->get('security.pin_max_attempts');
        $lockMinutes = (int) $this->settings->get('security.pin_lock_minutes');

        $attempts = $membership->pin_failed_attempts + 1;

        if ($attempts >= $maxAttempts) {
            $membership->forceFill([
                'pin_failed_attempts' => $attempts,
                'pin_locked_until' => now()->addMinutes($lockMinutes),
            ])->save();

            $this->audit->log(
                action: AuditAction::PIN_LOCKED,
                auditable: $membership,
                after: ['attempts' => $attempts, 'locked_minutes' => $lockMinutes, 'context' => 'shared_terminal'],
            );

            return;
        }

        $membership->forceFill(['pin_failed_attempts' => $attempts])->save();
    }

    private function resetFailedAttempts(TenantMembership $membership): void
    {
        if ($membership->pin_failed_attempts === 0 && $membership->pin_locked_until === null) {
            return;
        }

        $membership->forceFill([
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();
    }
}

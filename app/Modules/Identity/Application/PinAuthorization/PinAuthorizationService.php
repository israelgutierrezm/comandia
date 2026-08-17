<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\PinAuthorization;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AUTORIZACIÓN POR PIN — implementación de ADR-008.
 *
 * Este servicio es el **único** lugar del proyecto autorizado a evaluar la unión de los
 * roles de una persona. Un test estructural falla si esa consulta aparece en cualquier otro
 * archivo, porque sin ese candado la excepción a D9 se vuelve la regla por goteo.
 *
 * ## Por qué la unión de roles y no el rol activo
 *
 * Quien teclea su PIN **no tiene sesión**: se acercó a una terminal ajena, tecleó y volvió
 * a lo suyo. Su rol activo no está definido. Evaluar contra su rol por defecto produciría
 * "no autorizado" a alguien que sí tiene el permiso, y la salida que el tenant encontraría
 * es repartir roles más amplios — el resultado opuesto al que la regla buscaba. El control
 * compensatorio es el que §6.3 ya exige: registrar al actor real.
 *
 * ## Identificación por código de empleado + PIN (D84)
 *
 * Identificar por PIN solo obligaría a comparar el PIN teclado contra el hash bcrypt de
 * cada membresía del tenant: a coste 12 son ~250 ms por comparación, así que con veinte
 * empleados serían ~5 segundos por autorización, en hora pico y con el cliente delante.
 *
 * Con el código de empleado la búsqueda es por índice único y hay **una sola** comparación
 * de hash. Además el código funciona como segundo factor débil.
 *
 * Se descartó indexar un HMAC del PIN porque §10.4 exige que la llave de la aplicación sea
 * rotable, y rotarla invalidaría todos los hashes de búsqueda a la vez: la rotación se
 * convertiría en una migración de datos con los PIN irrecuperables.
 *
 * Consecuencia operativa: **quien no tiene código de empleado no puede autorizar.** Es
 * coherente —autorizar es un acto identificado— y queda anotado para la UI de alta de
 * personal.
 */
final readonly class PinAuthorizationService
{
    /**
     * Vida de la autorización. Corta a propósito: tiene que durar lo que tarda la
     * operación siguiente, no lo que dura el turno.
     */
    private const GRANT_TTL_SECONDS = 120;

    public function __construct(
        private Cache $cache,
        private AuditLogger $audit,
        private Settings $settings,
        private ModuleGate $modules,
        private MembershipNameResolver $names,
    ) {}

    /**
     * Valida el PIN y concede una autorización de un solo uso para un permiso concreto.
     *
     * @throws PinAuthorizationFailed
     */
    public function grant(string $employeeCode, string $pin, string $permission): PinGrant
    {
        $membership = TenantMembership::query()
            ->where('employee_code', Str::upper($employeeCode))
            ->where('status', MembershipStatus::Active->value)
            ->with(['user', 'employeeProfile'])
            ->first();

        if ($membership === null || ! $membership->hasPin()) {
            // No se distingue de un PIN incorrecto: distinguirlo permitiría enumerar
            // códigos de empleado válidos.
            $this->auditDenied(null, $permission, 'empleado inexistente, inactivo o sin PIN');

            throw PinAuthorizationFailed::invalid();
        }

        if ($membership->isPinLocked()) {
            $this->auditDenied($membership, $permission, 'PIN bloqueado');

            throw PinAuthorizationFailed::locked(
                (int) ceil(now()->diffInMinutes($membership->pin_locked_until, absolute: true)),
            );
        }

        if (! Hash::check($pin, (string) $membership->pin_hash)) {
            $this->registerFailedAttempt($membership, $permission);

            throw PinAuthorizationFailed::invalid();
        }

        if (! $this->hasPermissionAcrossRoles($membership, $permission)) {
            // El PIN era correcto pero esa persona no puede autorizar esto. El intento NO
            // cuenta como fallo de PIN: el PIN no falló, y bloquear a un gerente que se
            // equivocó de acción sería castigar lo que no es un ataque.
            $this->auditDenied($membership, $permission, 'sin permiso para la acción solicitada');

            throw PinAuthorizationFailed::notAuthorized();
        }

        $this->resetFailedAttempts($membership);

        $grant = new PinGrant(
            token: Str::random(48),
            permission: $permission,
            authorizerUlid: $membership->ulid,
            authorizerName: $this->names->resolve($membership)->short(),
            expiresAt: now()->addSeconds(self::GRANT_TTL_SECONDS),
        );

        $this->cache->put(
            $this->grantKey($grant->token),
            ['membership_id' => $membership->id, 'permission' => $permission],
            self::GRANT_TTL_SECONDS,
        );

        $this->audit->log(
            action: AuditAction::PIN_AUTHORIZATION_GRANTED,
            auditable: $membership,
            after: ['permission' => $permission],
            authorizedBy: $membership,
        );

        return $grant;
    }

    /**
     * Consume la autorización. De un solo uso y ligada al permiso.
     *
     * Devuelve la membresía del autorizador para que la operación la registre como actor
     * real en su propia entrada de auditoría.
     *
     * @throws PinAuthorizationFailed
     */
    public function consume(string $token, string $permission): TenantMembership
    {
        // `pull` lee y borra en una operación: es lo que hace que la autorización sea de un
        // solo uso sin una ventana entre leer y invalidar.
        /** @var array{membership_id: int, permission: string}|null $payload */
        $payload = $this->cache->pull($this->grantKey($token));

        if ($payload === null || $payload['permission'] !== $permission) {
            throw PinAuthorizationFailed::grantNotUsable();
        }

        $membership = TenantMembership::query()
            ->whereKey($payload['membership_id'])
            ->with(['user', 'employeeProfile'])
            ->first();

        // Se revalida el estado: la membresía pudo suspenderse entre la concesión y el uso.
        if ($membership === null || ! $membership->canOperate()) {
            throw PinAuthorizationFailed::grantNotUsable();
        }

        return $membership;
    }

    /**
     * LA EXCEPCIÓN DE ADR-008, y el único lugar donde vive.
     *
     * Evalúa la unión de los roles del autorizador **en este tenant**. Nunca roles de otros
     * tenants: el scope de `Role` y el team de Spatie lo garantizan, y además la membresía
     * ya viene acotada por el global scope.
     */
    private function hasPermissionAcrossRoles(TenantMembership $membership, string $permission): bool
    {
        if (! $this->modules->isActiveForPermission($permission)) {
            // Un tenant sin el módulo no autoriza su código ni con PIN.
            return false;
        }

        $user = $membership->user;

        if ($user === null) {
            // Una membresía sin credenciales no tiene roles, así que no puede autorizar.
            return false;
        }

        return $user->roles()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }

    private function registerFailedAttempt(TenantMembership $membership, string $permission): void
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
                after: ['attempts' => $attempts, 'locked_minutes' => $lockMinutes],
            );

            return;
        }

        $membership->forceFill(['pin_failed_attempts' => $attempts])->save();

        $this->auditDenied($membership, $permission, 'PIN incorrecto');
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

    /**
     * Se audita SIEMPRE, concedida y denegada (ADR-008, límite 4).
     *
     * Los fallos son la señal: cinco intentos seguidos sobre el mismo código de empleado
     * son un patrón, y sin registrarlos no existe.
     */
    private function auditDenied(?TenantMembership $membership, string $permission, string $reason): void
    {
        $this->audit->log(
            action: AuditAction::PIN_AUTHORIZATION_DENIED,
            auditable: $membership,
            after: ['permission' => $permission, 'reason' => $reason],
        );
    }

    private function grantKey(string $token): string
    {
        return "comandia.pin_grant.{$token}";
    }
}

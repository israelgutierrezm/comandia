<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use Carbon\CarbonImmutable;

/**
 * Las sesiones de la app de una persona: sus tokens de Sanctum (diseño de acceso, fase 1).
 *
 * ## Revocar es borrar el token
 *
 * Un token revocado no se marca: se borra, y la siguiente petición que lo presente responde 401. Es lo mismo que ya
 * hacía suspender a alguien. Lo que queda es el asiento en la bitácora del negocio del token, con el dispositivo y el
 * porqué (`how`): `logout` (salió en la app), `self` (lo cerró desde «Mis dispositivos»), `admin` (desde su ficha),
 * `password` (cambió o restableció su contraseña) o `idle` (llevaba 60 días sin usarse).
 *
 * ## Quién firma el asiento
 *
 * Quien lo cerró. Desde la ficha es el administrador, que actúa en su propio negocio con su contexto. En los demás casos
 * es la persona misma —o nadie, por falta de uso— en el negocio del token, que puede no ser el de la petición: por eso
 * esos pasan por `AccountAudit`.
 */
final readonly class AppSessions
{
    /** Días sin uso tras los que una sesión de la app se cierra sola (decisión 5 del diseño). */
    public const IDLE_DAYS = 60;

    public function __construct(
        private AuditLogger $audit,
        private AccountAudit $accountAudit,
    ) {}

    /**
     * Cierra una sesión de la app y lo asienta.
     *
     * @param  'logout'|'self'|'admin'|'password'|'idle'  $how
     */
    public function revoke(PersonalAccessToken $token, string $how): void
    {
        $before = [
            'device' => (string) $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
        ];

        $after = ['how' => $how];

        match ($how) {
            // El administrador actúa en SU negocio, que es el del token: la membresía llega de la ruta, acotada.
            'admin' => $this->audit->log(
                action: AuditAction::APP_SESSION_REVOKED,
                auditable: TenantMembership::query()->find($token->membership_id),
                before: $before,
                after: $after,
            ),

            'idle' => $this->accountAudit->asSystem(
                (int) $token->tenant_id,
                (int) $token->membership_id,
                AuditAction::APP_SESSION_REVOKED,
                $before,
                $after,
            ),

            default => $this->accountAudit->asMember(
                (int) $token->tenant_id,
                (int) $token->membership_id,
                AuditAction::APP_SESSION_REVOKED,
                $before,
                $after,
            ),
        };

        $token->delete();
    }

    /**
     * Cierra TODAS las sesiones de la app de una persona, en todos sus negocios —salvo, si se indica, la sesión desde la
     * que se pidió—. Devuelve cuántas.
     *
     * @param  'self'|'password'  $how
     */
    public function revokeAllOf(User $user, string $how, ?PersonalAccessToken $except = null): int
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get();

        foreach ($tokens as $token) {
            $this->revoke($token, $how);
        }

        return $tokens->count();
    }

    /**
     * Cierra las sesiones que llevan {@see IDLE_DAYS} días sin usarse. Un teléfono perdido hace un año dejaba de ser una
     * puerta abierta sólo si alguien se acordaba de suspender a la persona.
     *
     * «Sin usarse» cuenta desde el último uso, y si nunca se usó, desde que se emitió.
     */
    public function pruneIdle(?CarbonImmutable $now = null): int
    {
        $limite = ($now ?? CarbonImmutable::now())->subDays(self::IDLE_DAYS);

        $tokens = PersonalAccessToken::query()
            ->where(fn ($query) => $query
                ->where('last_used_at', '<', $limite)
                ->orWhere(fn ($nunca) => $nunca->whereNull('last_used_at')->where('created_at', '<', $limite)))
            ->get();

        foreach ($tokens as $token) {
            $this->revoke($token, 'idle');
        }

        return $tokens->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitationRole;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Jobs\SendMembershipInvitation;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\TenantLimits;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Dar acceso con una invitación (diseño de acceso, fase 3).
 *
 * ## Por qué una invitación y no una contraseña tecleada
 *
 * El alta pedía la contraseña de la persona y la tecleaba quien la daba de alta: alguien más la conocía desde el primer
 * día. Y si el correo ya tenía cuenta en otro negocio, la sumaba a éste sin preguntarle. La invitación resuelve las dos
 * cosas: la persona crea su contraseña —o acepta con la suya— desde un enlace que le llega a ella, y así da su
 * consentimiento y prueba que el correo es suyo.
 *
 * ## La cuenta no existe hasta que se acepta
 *
 * No se crea ni se liga ninguna cuenta global al invitar: la membresía espera sin usuario, con su nombre en el perfil
 * laboral (D66). Al aceptar se crea la cuenta —o se liga la que ya tenía— y la membresía queda activa con los roles de la
 * invitación. Un correo mal escrito no deja una cuenta basura en la plataforma.
 *
 * ## Una vigente por persona
 *
 * Reenviar cancela la anterior y manda otra con un token nuevo, bajo lock de la membresía. El enlace en claro sólo
 * existe en el correo y en la respuesta de quien invita (para copiarlo y mandarlo por WhatsApp si el correo no llega);
 * aquí se guarda su SHA-256.
 */
final readonly class MembershipInvitations
{
    public function __construct(
        private AuditLogger $audit,
        private AccountAudit $accountAudit,
        private TenantContext $tenants,
        private TenantLimits $limits,
    ) {}

    /**
     * Invita (o vuelve a invitar) a una persona del negocio.
     *
     * @param  list<string>|null  $roleUlids  null = los de la invitación anterior, si la había
     */
    public function invite(TenantMembership $membership, string $email, ?array $roleUlids, TenantMembership $invitedBy): IssuedInvitation
    {
        $correo = mb_strtolower(trim($email));

        return DB::transaction(function () use ($membership, $correo, $roleUlids, $invitedBy): IssuedInvitation {
            $persona = TenantMembership::query()->with('user')->whereKey($membership->id)->lockForUpdate()->sole();

            $this->assertInvitable($persona, $correo);

            $anterior = MembershipInvitation::query()
                ->where('membership_id', $persona->id)
                ->open()
                ->latest('id')
                ->first();

            $roles = $roleUlids === null
                ? ($anterior?->roles()->get() ?? collect())
                : Role::query()->whereIn('ulid', $roleUlids)->get();

            // Una sola vigente: la anterior deja de servir en cuanto sale la nueva.
            MembershipInvitation::query()
                ->where('membership_id', $persona->id)
                ->open()
                ->update(['revoked_at' => CarbonImmutable::now()]);

            $token = Str::random(64);

            $invitacion = MembershipInvitation::create([
                'membership_id' => $persona->id,
                'email' => $correo,
                'token_hash' => MembershipInvitation::hashToken($token),
                'invited_by_membership_id' => $invitedBy->id,
                'expires_at' => CarbonImmutable::now()->addDays(MembershipInvitation::DAYS_VALID),
            ]);

            foreach ($roles as $rol) {
                MembershipInvitationRole::create(['invitation_id' => $invitacion->id, 'role_id' => $rol->id]);
            }

            $this->audit->log(
                action: AuditAction::INVITATION_SENT,
                auditable: $persona,
                after: [
                    'email' => $correo,
                    'expires_at' => $invitacion->expires_at->toIso8601String(),
                    'roles' => $roles->pluck('name')->values()->all(),
                    'replaced' => $anterior?->ulid,
                ],
                actor: $invitedBy,
            );

            $enlace = self::link($token);

            // Tras el commit: si la transacción se deshace, no sale un correo con un enlace que no existe.
            DB::afterCommit(fn () => SendMembershipInvitation::dispatch(
                (int) $persona->tenant_id,
                (int) $invitacion->id,
                $enlace,
            ));

            return new IssuedInvitation($invitacion->refresh(), $enlace);
        });
    }

    /**
     * Cancela la invitación vigente de una persona.
     */
    public function revoke(TenantMembership $membership): void
    {
        DB::transaction(function () use ($membership): void {
            $abierta = MembershipInvitation::query()
                ->where('membership_id', $membership->id)
                ->open()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($abierta === null) {
                throw new NotFoundHttpException('Esta persona no tiene una invitación pendiente.');
            }

            MembershipInvitation::query()
                ->where('membership_id', $membership->id)
                ->open()
                ->update(['revoked_at' => CarbonImmutable::now()]);

            $this->audit->log(
                action: AuditAction::INVITATION_REVOKED,
                auditable: $membership,
                before: ['email' => $abierta->email, 'expires_at' => $abierta->expires_at->toIso8601String()],
            );
        });
    }

    /**
     * La invitación de un enlace, en el estado que esté —vigente, vencida, usada o cancelada—, o `null` si el token no
     * es de ninguna.
     *
     * Se busca SIN contexto de negocio porque el enlace llega desde fuera: es el token lo que dice de qué negocio es, igual
     * que la tienda por su slug o el dispositivo por su secreto. Excepción declarada en `AuthorizationDisciplineTest`.
     */
    public function findByToken(string $token): ?MembershipInvitation
    {
        return MembershipInvitation::query()
            ->withoutGlobalScopes()
            ->with('tenant')
            ->where('token_hash', MembershipInvitation::hashToken($token))
            ->first();
    }

    /**
     * La persona acepta: queda ligada a su cuenta, activa y con los roles de la invitación.
     *
     * Quien llama ya comprobó que la cuenta es de quien acepta (creándola, con su contraseña o con su sesión abierta).
     * Aquí se comprueba todo lo demás, sobre filas bloqueadas: dos clics seguidos no ligan ni activan dos veces.
     */
    public function accept(MembershipInvitation $invitation, User $user): TenantMembership
    {
        return $this->tenants->runFor((int) $invitation->tenant_id, function () use ($invitation, $user): TenantMembership {
            $persona = DB::transaction(function () use ($invitation, $user): TenantMembership {
                $invitacion = MembershipInvitation::query()->whereKey($invitation->id)->lockForUpdate()->sole();

                if (! $invitacion->isPending()) {
                    throw new GoneHttpException('Esta invitación ya no vale: venció, ya se usó o la cancelaron. Pide otra.');
                }

                if (mb_strtolower((string) $user->email) !== mb_strtolower($invitacion->email)) {
                    throw new ConflictHttpException('Esta invitación es para otro correo.');
                }

                $persona = TenantMembership::query()->whereKey($invitacion->membership_id)->lockForUpdate()->sole();

                if (! in_array($persona->status, [MembershipStatus::Invited, MembershipStatus::Active], true)) {
                    throw new ConflictHttpException('Tu acceso a este negocio está suspendido. Habla con quien te invitó.');
                }

                if ($persona->user_id !== null && (int) $persona->user_id !== (int) $user->id) {
                    throw new ConflictHttpException('Esta ficha ya está ligada a otra cuenta.');
                }

                // Una invitada no ocupa plaza del plan hasta que acepta (D4 mide las activas). Si mientras tanto el
                // negocio llenó sus plazas, aceptar no puede rebasarlas: invitar sería la puerta para saltarse el límite.
                if ($persona->status === MembershipStatus::Invited && ! $this->limits->allows(TenantLimitKey::MaxUsers)) {
                    throw new ConflictHttpException(
                        'El negocio llegó al límite de personas de su plan. Pide a quien te invitó que libere una plaza.',
                    );
                }

                $yaEsta = TenantMembership::query()
                    ->where('user_id', $user->id)
                    ->whereKeyNot($persona->id)
                    ->exists();

                if ($yaEsta) {
                    throw new ConflictHttpException(
                        'Ya formas parte de este negocio con otra ficha. Pide a quien te invitó que revise tu alta.',
                    );
                }

                $roles = $invitacion->roles()->get();

                $persona->update([
                    'user_id' => $user->id,
                    'status' => MembershipStatus::Active,
                    'default_role_id' => $persona->default_role_id ?? $roles->first()?->id,
                ]);

                if ($roles->isNotEmpty()) {
                    // Spatie con equipos = negocio: el contexto abierto arriba fija el equipo, así que sólo toca los roles
                    // de ESTE negocio.
                    $user->syncRoles($roles);
                }

                $invitacion->update(['accepted_at' => CarbonImmutable::now()]);

                return $persona->refresh();
            });

            $this->accountAudit->asMember(
                (int) $invitation->tenant_id,
                (int) $persona->id,
                AuditAction::INVITATION_ACCEPTED,
                after: ['email' => $invitation->email],
            );

            return $persona;
        });
    }

    /**
     * El enlace de una invitación. Con `app.url` y nunca con el host de la petición, por lo mismo que el de restablecer
     * la contraseña: un `Host` falso no puede desviar el enlace hacia otro dominio.
     */
    public static function link(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/invitacion/'.$token;
    }

    private function assertInvitable(TenantMembership $persona, string $correo): void
    {
        if (in_array($persona->status, [MembershipStatus::Suspended, MembershipStatus::Terminated], true)) {
            throw new ConflictHttpException('Esta persona está suspendida o dada de baja: reactívala antes de invitarla.');
        }

        if ($persona->user !== null && $persona->status === MembershipStatus::Active) {
            throw new ConflictHttpException(sprintf('Ya entra al sistema con %s.', $persona->user->email));
        }

        // Una invitada de antes de las invitaciones ya tiene cuenta ligada: el enlace va al correo de esa cuenta.
        if ($persona->user !== null && mb_strtolower((string) $persona->user->email) !== $correo) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Su cuenta es %s: la invitación tiene que ir a ese correo.',
                $persona->user->email,
            ));
        }

        // Alguien de ESTE negocio ya entra con ese correo. Sólo se mira este negocio: de los demás no se dice nada.
        $yaEsta = TenantMembership::query()
            ->whereKeyNot($persona->id)
            ->whereHas('user', fn ($query) => $query->where('email', $correo))
            ->exists();

        if ($yaEsta) {
            throw new ConflictHttpException('Alguien de este negocio ya entra con ese correo.');
        }
    }
}

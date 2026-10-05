<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Modules\Configuration\Application\TenantMailer;
use App\Modules\Identity\Application\MembershipNameResolver;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;
use App\Modules\Identity\Mail\MembershipInvitationMail;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Manda el correo de una invitación, con el correo del negocio (diseño de acceso, fase 3).
 *
 * En cola (`default`): invitar no espera a un servidor de correo, y un SMTP caído no deshace la invitación —el enlace
 * sigue sirviendo y se puede copiar desde la pantalla—. El contexto del negocio se abre aquí, porque `TenantMailer` lee
 * la configuración de correo del negocio actual y un trabajo no tiene petición que la haya puesto.
 *
 * Si la invitación se canceló o se reemplazó antes de que esto corriera, no se manda nada: el correo llevaría un enlace
 * que ya no sirve.
 */
final class SendMembershipInvitation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $invitationId,
        private readonly string $link,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function handle(TenantContext $tenants, TenantMailer $mailer, MembershipNameResolver $names): void
    {
        $tenants->runFor($this->tenantId, function () use ($mailer, $names): void {
            $invitacion = MembershipInvitation::query()
                ->with(['tenant', 'invitedBy.user', 'invitedBy.employeeProfile', 'roles'])
                ->find($this->invitationId);

            if ($invitacion === null || ! $invitacion->isPending()) {
                return;
            }

            $config = $mailer->settings();

            $mailer->send($invitacion->email, new MembershipInvitationMail(
                businessName: (string) $invitacion->tenant?->name,
                inviterName: $invitacion->invitedBy === null ? 'Tu negocio' : $names->resolve($invitacion->invitedBy)->short(),
                roles: $invitacion->roles->pluck('name')->values()->all(),
                link: $this->link,
                daysValid: MembershipInvitation::DAYS_VALID,
                fromAddress: $config?->from_address,
                fromName: $config?->from_name,
            ));
        });
    }
}

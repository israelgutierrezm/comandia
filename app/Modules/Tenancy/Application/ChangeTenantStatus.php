<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cambia el estado comercial de un negocio y deja constancia en su historial (INMUTABLE, D75).
 *
 * Es el servicio que D6/D70 dejaron pendiente: hasta ahora el único que escribía una transición era `ProvisionTenant`
 * (la de nacimiento). Sólo permite transiciones legales (`TenantStatus::canTransitionTo`) — activar, suspender, poner
 * en sólo lectura, reactivar—; una ilegal se rechaza en lugar de dejar un negocio en un estado sin sentido.
 *
 * La escritura de la transición ocurre DENTRO del contexto del negocio: `tenant_status_transitions` lleva scope de
 * tenant, y el panel de plataforma corre sin contexto (no pertenece a ningún negocio), así que hay que fijarlo aquí.
 */
final readonly class ChangeTenantStatus
{
    public function __construct(private TenantContext $context) {}

    public function change(
        Tenant $tenant,
        TenantStatus $target,
        ?string $reason = null,
        ?int $platformAdminId = null,
    ): Tenant {
        $current = $tenant->status;

        if (! $current->canTransitionTo($target)) {
            throw new ConflictHttpException(sprintf(
                'No se puede pasar de «%s» a «%s».',
                $current->label(),
                $target->label(),
            ));
        }

        return $this->context->runFor($tenant->id, function () use ($tenant, $current, $target, $reason, $platformAdminId): Tenant {
            $tenant->update(['status' => $target]);

            TenantStatusTransition::create([
                'from_status' => $current,
                'to_status' => $target,
                'reason' => $reason,
                'actor_platform_admin_id' => $platformAdminId,
            ]);

            return $tenant->refresh();
        });
    }
}

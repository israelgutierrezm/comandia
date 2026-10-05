<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Abre la sesión web en el negocio de una membresía: lo que hace el acceso cuando la persona entra a un negocio.
 *
 * Salió de `LoginController` cuando aceptar una invitación también tuvo que dejar a la persona dentro de su negocio
 * (diseño de acceso, fase 3): dos copias de estas tres cosas acabarían haciendo cosas distintas.
 */
final readonly class StartTenantSession
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $tenants,
    ) {}

    public function enter(Request $request, TenantMembership $membership): void
    {
        $tenantId = (int) $membership->tenantId();

        $request->session()->put('tenant_id', $tenantId);

        $this->tenants->runFor(
            $tenantId,
            // El rol activo se reinicia al iniciar sesión (D234): la jornada empieza en el rol por
            // omisión, no en el que alguien dejó elegido al cerrar. Va dentro del contexto porque la
            // membresía lleva global scope de tenant.
            fn () => $membership->forgetActiveRole(),
        );

        $this->tenants->runFor(
            $tenantId,
            // La membresía va como actor explícito: el contexto de esta petición se resolvió cuando
            // todavía no había sesión, así que está vacío, y sin esto el asiento del inicio de sesión
            // quedaba atribuido a «Sistema».
            fn () => $this->audit->log(
                action: AuditAction::LOGIN,
                auditable: $membership,
                actor: $membership,
            ),
        );
    }
}

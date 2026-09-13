<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Auth;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve al OPERADOR de una terminal compartida y arma su contexto (ADR-012/ADR-014).
 *
 * Es la parte delicada —y COMPARTIDA— de la resolución: dado un dispositivo ya validado y su capa de
 * operador (sea de la sesión web o de la fila del dispositivo en el kiosco móvil), decide si hay un
 * operador que pueda operar AHORA y, si lo hay, satisface el gate y arma el `RequestContext`. Que los
 * dos caminos (cookie y token) pasen por aquí es a propósito: esta lógica —inactividad, rol activo,
 * atribución— no debe existir en dos copias que se desvíen.
 *
 * El scope de tenant ya debe estar abierto por el llamador (el tenant sale del dispositivo, ADR-002).
 */
final class SharedTerminalResolver
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContextHolder $holder,
    ) {}

    /**
     * Si hay un operador fresco y con permiso de operar, pone el {@see SharedTerminalPrincipal} en la
     * guardia web (satisface `auth:sanctum` sin usuario) y arma el `RequestContext`. Si el operador
     * caducó por inactividad o ya no puede operar, lo olvida en el estado y deja la terminal en el
     * bloqueo (el gate dará 401). Sin operador, no hace nada.
     */
    public function resolveOperator(TerminalDevice $device, SharedTerminalState $state): void
    {
        $operatorId = $state->operatorMembershipId();

        if ($operatorId === null) {
            // En el bloqueo: sin operador no hay identidad. El gate protegerá el POS.
            return;
        }

        $terminal = Terminal::query()->find($device->terminal_id);

        if ($terminal === null || ! $terminal->isActive()) {
            $state->clearOperator();

            return;
        }

        // Inactividad: pasado el umbral (por sucursal, D20), la sesión de operación caduca y vuelve al bloqueo.
        $idleLimit = (int) $this->settings->forBranch('pos.shared_terminal_idle_seconds', (int) $terminal->branch_id);
        $idle = $state->secondsSinceActivity();

        if ($idle !== null && $idle > $idleLimit) {
            $state->clearOperator();

            return;
        }

        $membership = TenantMembership::query()->find($operatorId);

        if ($membership === null || ! $membership->canOperate()) {
            $state->clearOperator();

            return;
        }

        $branch = Branch::query()->find($terminal->branch_id);
        $tenant = Tenant::query()->find($device->tenant_id);
        $role = $membership->defaultRole;

        if ($branch === null || $tenant === null || $role === null) {
            return;
        }

        $state->touchActivity();

        // Satisface `auth:sanctum` sin usuario: Sanctum, cuando la guardia web ya trae un usuario, lo toma
        // a él y no mira el token. Es un principal transitorio (setUser, no login): no persiste nada.
        Auth::guard('web')->setUser(new SharedTerminalPrincipal((int) $membership->id));

        $this->holder->set(RequestContext::forSharedTerminalOperator(
            tenant: $tenant,
            membership: $membership,
            activeRole: $role,
            activeBranch: $branch,
            terminal: $terminal,
        ));
    }
}

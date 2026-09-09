<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Application\Auth\SharedTerminalPrincipal;
use App\Modules\Shared\Application\Auth\SharedTerminalSession;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolución de la TERMINAL COMPARTIDA (ADR-012): convierte una sesión de dispositivo + operador en
 * un contexto operable, sin `User`.
 *
 * ## Dónde encaja y por qué es inerte para todo lo demás
 *
 * Corre en la tubería de `/api/v1`, **antes** del gate `auth:sanctum`. Si la petición no trae una
 * sesión de dispositivo, sale de inmediato: para la SPA de usuario y la app Flutter esto no existe.
 * Sólo actúa cuando hay una sesión de dispositivo, que únicamente crean los endpoints de terminal
 * compartida.
 *
 * ## Qué hace cuando SÍ hay dispositivo
 *
 * 1. Valida el dispositivo (existe, no revocado). Si no, olvida la sesión: el gate dará 401.
 * 2. Si no hay operador, o caducó por inactividad, deja pasar SIN autenticar: la terminal está en el
 *    bloqueo y las rutas protegidas responden 401 (hay que re-teclear el PIN).
 * 3. Con operador válido: refresca la actividad, **satisface el gate** poniendo un
 *    {@see SharedTerminalPrincipal} en la guardia web (Sanctum lo acepta como principal de sesión), y
 *    arma el `RequestContext` del operador —con su rol activo, su terminal y su sucursal—. La
 *    autorización (`Authorize`) opera sobre ese contexto, exactamente como con un usuario.
 *
 * `ResolveTenantContext` corre igual, ve que el principal no es un `User` y no toca el contexto que
 * este middleware ya puso.
 */
final class ResolveSharedTerminal
{
    public function __construct(
        private readonly SharedTerminalSession $session,
        private readonly TenantContext $tenantContext,
        private readonly ContextHolder $holder,
        private readonly Settings $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $this->session->hasDevice()) {
            return $next($request);
        }

        // Esta petición es de un DISPOSITIVO, y su autenticación la decide POR COMPLETO este middleware.
        // Se parte de cero: se olvidan los guards para que ningún principal quede en pie salvo el que se
        // ponga en el camino de éxito de abajo. En producción cada petición es un proceso nuevo y el guard
        // ya nace vacío; esto lo hace cierto también cuando el guard se reutiliza (misma petición, o el
        // contenedor de una prueba) — sin él, un operador ya caducado o al que se le hizo «Salir» seguiría
        // autenticado por el principal que dejó una petición anterior.
        Auth::forgetGuards();

        $tenantId = $this->session->tenantId();

        if ($tenantId === null) {
            $this->session->clearAll();

            return $next($request);
        }

        // Abrir el scope de tenant ANTES de consultar: los modelos de dominio lo exigen (ADR-002).
        $this->tenantContext->set($tenantId);

        $device = TerminalDevice::query()->find($this->session->deviceId());

        if ($device === null || $device->isRevoked()) {
            $this->session->clearAll();

            return $next($request);
        }

        $device->touchLastSeen();

        $operatorId = $this->session->operatorMembershipId();

        if ($operatorId === null) {
            // Dispositivo en el bloqueo: sin operador no hay identidad. El gate protegerá el POS.
            return $next($request);
        }

        $terminal = Terminal::query()->find($device->terminal_id);

        if ($terminal === null || ! $terminal->isActive()) {
            $this->session->clearOperator();

            return $next($request);
        }

        // Inactividad: pasado el umbral (por sucursal, D20), la sesión de operación caduca y vuelve al bloqueo.
        $idleLimit = (int) $this->settings->forBranch('pos.shared_terminal_idle_seconds', (int) $terminal->branch_id);
        $idle = $this->session->secondsSinceActivity();

        if ($idle !== null && $idle > $idleLimit) {
            $this->session->clearOperator();

            return $next($request);
        }

        $membership = TenantMembership::query()->find($operatorId);

        if ($membership === null || ! $membership->canOperate()) {
            $this->session->clearOperator();

            return $next($request);
        }

        $branch = Branch::query()->find($terminal->branch_id);
        $tenant = Tenant::query()->find($tenantId);
        $role = $membership->defaultRole;

        if ($branch === null || $tenant === null || $role === null) {
            return $next($request);
        }

        $this->session->touchActivity();

        // Satisface `auth:sanctum` sin usuario: Sanctum, en petición con estado, toma el usuario de la
        // guardia web. Es un principal transitorio (setUser, no login): no persiste nada.
        Auth::guard('web')->setUser(new SharedTerminalPrincipal((int) $membership->id));

        $this->holder->set(RequestContext::forSharedTerminalOperator(
            tenant: $tenant,
            membership: $membership,
            activeRole: $role,
            activeBranch: $branch,
            terminal: $terminal,
        ));

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Application\Auth\SharedTerminalResolver;
use App\Modules\Shared\Application\Auth\SharedTerminalSession;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
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
        private readonly SharedTerminalResolver $resolver,
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

        // La capa de operador (inactividad, rol activo, contexto) se resuelve igual por cookie y por token.
        $this->resolver->resolveOperator($device, $this->session);

        return $next($request);
    }
}

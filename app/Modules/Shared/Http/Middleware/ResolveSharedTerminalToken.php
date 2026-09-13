<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Application\Auth\SharedTerminalResolver;
use App\Modules\Shared\Application\Auth\TerminalDeviceState;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolución de la terminal compartida por TOKEN (ADR-014): el gemelo de {@see ResolveSharedTerminal}
 * para el kiosco móvil, que autentica por token y no por cookie.
 *
 * ## Por qué un token propio y NO Sanctum
 *
 * Igual que el agente de impresión: un dispositivo no es un usuario. Si el token fuera un token Sanctum,
 * el gate `auth:sanctum` lo aceptaría por sí solo —el dispositivo pasaría al POS SIN operador—. Con un
 * token propio (no Sanctum),
 * un dispositivo en el bloqueo no pone principal y el gate responde 401: hay que teclear el PIN. Sólo un
 * operador fresco satisface el gate, exactamente como por cookie.
 *
 * ## Qué hace
 *
 * Corre en `/api/v1` antes del gate. Inerte si no viene el encabezado del token —la SPA de usuario y la
 * app en modo normal no lo mandan—. Con un token válido: abre el scope de tenant DEL DISPOSITIVO
 * (ADR-002), deja el dispositivo a mano para la antesala (identificar/salir) y delega la resolución del
 * operador en {@see SharedTerminalResolver}, la misma del camino por cookie.
 */
final class ResolveSharedTerminalToken
{
    /** Encabezado que porta el token del dispositivo (paralelo a `X-Print-Agent-Token`). */
    public const HEADER = 'X-Terminal-Token';

    /** Atributo donde queda el dispositivo resuelto, para la antesala (identificar/salir). */
    public const ATRIBUTO = 'shared_terminal_device';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SharedTerminalResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->header(self::HEADER, '');

        if ($token === '') {
            return $next($request);
        }

        // El encabezado del token marca la petición como de un DISPOSITIVO: se parte de cero para que ningún
        // principal de una petición anterior sobreviva en un guard reutilizado. Va ANTES de resolver el token
        // —a propósito— para que un token ya revocado no herede el operador que dejó una petición previa (en
        // producción cada petición nace limpia; esto lo hace cierto también con el contenedor de una prueba).
        Auth::forgetGuards();

        $device = TerminalDevice::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // Token inexistente o dispositivo revocado: inerte y sin principal → el POS detrás dará 401 por el
        // gate. No es un 401 aquí para no convertir este middleware, que corre en TODA la API, en un oráculo.
        if ($device === null || $device->isRevoked()) {
            return $next($request);
        }

        // El tenant sale del DISPOSITIVO, nunca de la petición (ADR-002), igual que el agente de impresión.
        $this->tenantContext->set((int) $device->tenant_id);

        $device->touchLastSeen();

        // La antesala (identificar operador / salir) lee el dispositivo de aquí; ya está resuelto y validado.
        $request->attributes->set(self::ATRIBUTO, $device);

        // Misma resolución del operador que por cookie: inactividad, rol activo, contexto y principal.
        $this->resolver->resolveOperator($device, new TerminalDeviceState($device));

        return $next($request);
    }
}

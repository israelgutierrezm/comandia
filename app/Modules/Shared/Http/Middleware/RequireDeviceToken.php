<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un TOKEN DE DISPOSITIVO válido (ADR-014): el gemelo por token de {@see RequireDeviceSession}.
 *
 * Gatea la antesala del kiosco móvil —identificar operador, salir—: sólo tienen sentido sobre un
 * dispositivo cuyo token ya resolvió {@see ResolveSharedTerminalToken} (que deja el dispositivo en el
 * atributo de la petición). NO exige operador: identificar es lo que lo crea, y salir puede ocurrir con
 * la terminal ya en el bloqueo. Sin token de dispositivo → 401, no un 500 por falta de contexto.
 */
final class RequireDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has(ResolveSharedTerminalToken::ATRIBUTO)) {
            return new JsonResponse([
                'type' => 'no_device_token',
                'title' => 'Esta terminal no tiene un token de dispositivo válido. Vuelve a emparejarla.',
                'status' => 401,
            ], 401);
        }

        return $next($request);
    }
}

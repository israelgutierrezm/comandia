<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Application\Auth\SharedTerminalSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la petición venga de una SESIÓN DE DISPOSITIVO (ADR-012).
 *
 * Gatea los endpoints de la pantalla de bloqueo —identificar operador, salir—: sólo tienen sentido
 * sobre un dispositivo ya enrolado y con su secreto canjeado. Un cliente cualquiera (la SPA de usuario,
 * la app Flutter, curl) que los llame sin sesión de dispositivo recibe 401, no un 500 por falta de
 * contexto.
 *
 * NO exige operador: identificar es precisamente lo que crea el operador, y salir puede ocurrir con la
 * terminal ya en el bloqueo (idempotente). Lo que exige es la capa de DISPOSITIVO.
 *
 * No confundir con `auth:sanctum`: ese gate protege el POS y sólo lo pasa un dispositivo CON operador
 * (v. {@see ResolveSharedTerminal}). Éste protege la antesala, donde todavía no hay operador.
 */
final class RequireDeviceSession
{
    public function __construct(private readonly SharedTerminalSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $this->session->hasDevice()) {
            return new JsonResponse([
                'type' => 'no_device_session',
                'title' => 'Esta terminal no tiene una sesión de dispositivo. Vuelve a enrolarla o recarga.',
                'status' => 401,
            ], 401);
        }

        return $next($request);
    }
}

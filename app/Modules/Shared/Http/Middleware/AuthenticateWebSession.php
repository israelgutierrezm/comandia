<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Http\Support\SessionPasswordFingerprint;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saca de las pantallas web a una sesión cuya contraseña cambió o que otra cerró con «Cerrar mis otras sesiones» (diseño
 * de acceso, D372).
 *
 * Es el `AuthenticateSession` de Laravel con una diferencia: revisa SIEMPRE el guardia `web`, por su nombre. El de Laravel
 * usa el guardia por omisión, y ése lo cambia `auth:sanctum` al autenticar una llamada a la API. En producción cada
 * petición nace con el guardia web, pero dentro de un mismo proceso —las pruebas, un worker largo— una llamada previa a la
 * API lo dejaba en Sanctum, y la revisión de una pantalla reventaba (`RequestGuard::viaRemember`) o guardaba el cifrado
 * bajo otra llave y dejaba pasar a quien no debía.
 *
 * Comparte la llave `password_hash_web` y el formato (HMAC del cifrado, con respaldo al cifrado en crudo) con el
 * `AuthenticateSession` de Sanctum, que hace lo mismo en `/api`: las dos revisiones ven la misma sesión. La comparación
 * vive en {@see SessionPasswordFingerprint}, que comparten la plataforma y los clientes de la tienda.
 *
 * Inerte sin usuario y con el operador de una terminal compartida, que no tiene contraseña (ADR-012).
 */
final class AuthenticateWebSession
{
    private const GUARD = 'web';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SessionPasswordFingerprint $fingerprint,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard(self::GUARD);

        if (! $request->hasSession() || ! $guard instanceof SessionGuard) {
            return $next($request);
        }

        $usuario = $guard->user();

        if ($usuario === null || ! $usuario->getAuthPassword()) {
            return $next($request);
        }

        $cifrado = (string) $usuario->getAuthPassword();

        // «Mantener la sesión abierta»: la cookie lleva el cifrado con el que se emitió.
        if ($guard->viaRemember() && ! $this->fingerprint->recallerMatches($request, $guard, $cifrado)) {
            $this->logout($request, $guard);
        }

        if (! $this->fingerprint->sessionMatches($request, $guard, self::GUARD, $cifrado)) {
            $this->logout($request, $guard);
        }

        return tap($next($request), function () use ($request, $guard): void {
            // Al terminar se guarda el cifrado vigente: si esta misma petición cambió la contraseña, ESTA sesión sigue.
            if ($guard->user() !== null) {
                $this->fingerprint->store($request, $guard, self::GUARD);
            }
        });
    }

    /**
     * @throws AuthenticationException
     */
    private function logout(Request $request, SessionGuard $guard): never
    {
        $guard->logoutCurrentDevice();

        $request->session()->flush();

        throw new AuthenticationException('Unauthenticated.', [self::GUARD]);
    }
}

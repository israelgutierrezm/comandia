<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Shared\Http\Support\SessionPasswordFingerprint;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un super administrador de plataforma autenticado (guard `platform`).
 *
 * Nunca resuelve contexto de tenant: esta superficie no pertenece a ningún negocio. Un visitante sin sesión de
 * plataforma se manda al acceso; en ningún momento se le dice si un correo es o no super admin.
 *
 * Revisa además la huella de la contraseña (D382): si cambió desde que esta sesión entró —desde «Mi cuenta» en otra
 * sesión, o desde la consola—, la sesión se cierra y vuelve al acceso con el aviso. Aquí sí se invalida la sesión
 * completa: la cookie `_plataforma` es propia, no la comparte nadie. La sesión que hizo el cambio sigue, porque al
 * terminar la petición guarda la huella nueva.
 */
final class EnsureSuperAdmin
{
    private const GUARD = 'platform';

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SessionPasswordFingerprint $fingerprint,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard(self::GUARD);

        if (! $guard instanceof SessionGuard || ! $guard->check()) {
            return redirect()->route('platform.login');
        }

        $cifrado = (string) $guard->user()?->getAuthPassword();

        $vigente = (! $guard->viaRemember() || $this->fingerprint->recallerMatches($request, $guard, $cifrado))
            && $this->fingerprint->sessionMatches($request, $guard, self::GUARD, $cifrado);

        if (! $vigente) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('platform.login')
                ->with('error', 'Tu sesión se cerró porque la contraseña cambió. Entra con la nueva.');
        }

        return tap($next($request), function () use ($request, $guard): void {
            if ($guard->user() !== null) {
                $this->fingerprint->store($request, $guard, self::GUARD);
            }
        });
    }
}

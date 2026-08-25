<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un super administrador de plataforma autenticado (guard `platform`).
 *
 * Nunca resuelve contexto de tenant: esta superficie no pertenece a ningún negocio. Un visitante sin sesión de
 * plataforma se manda al acceso; en ningún momento se le dice si un correo es o no super admin.
 */
final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('platform')->check()) {
            return redirect()->route('platform.login');
        }

        return $next($request);
    }
}

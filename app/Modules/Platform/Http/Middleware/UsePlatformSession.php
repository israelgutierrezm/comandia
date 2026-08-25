<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aísla la cookie de sesión de la plataforma de la de los negocios.
 *
 * Corre ANTES de `StartSession` y renombra la cookie de sesión sólo para `/plataforma`: así el super admin y el personal
 * de un negocio no comparten cookie, y cerrar sesión en uno no toca al otro. Es la mitad de «totalmente aislado» que el
 * guard separado no cubre por sí solo — el guard aísla la IDENTIDAD; esto aísla la COOKIE—.
 */
final class UsePlatformSession
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.cookie' => config('session.cookie').'_plataforma']);

        return $next($request);
    }
}

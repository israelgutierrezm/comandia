<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Middleware;

use App\Modules\Shared\Application\Auth\SharedTerminalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quién puede entrar al SHELL web del POS (ADR-012, modo kiosco).
 *
 * Reemplaza a `auth` en las rutas de `admin/pos` porque el POS lo operan DOS clases de principal, no una:
 *
 *  - Un **usuario** con sesión normal (cajero, gerente).
 *  - Un **operador de terminal compartida**: no tiene `User`, pero `ResolveSharedTerminal` ya lo dejó en el
 *    guard `web` como principal transitorio, así que `check()` lo reconoce igual.
 *
 * La diferencia con `auth` está en el bloqueo: un dispositivo enrolado pero **sin operador** no es un
 * intruso, es una terminal esperando el PIN. Mandarlo a `/login` —lo que haría `auth`— no tiene sentido:
 * un dispositivo no inicia sesión de usuario. Se le manda a su pantalla de bloqueo, donde el operador se
 * identifica y vuelve al POS. Sólo cuando NO hay ni usuario ni dispositivo se cae al login.
 */
final class EnsurePosShellAccess
{
    public function __construct(private readonly SharedTerminalSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Usuario real u operador de terminal (a este último lo puso en el guard web ResolveSharedTerminal).
        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        // Dispositivo enrolado sin operador: al bloqueo, no al login.
        if ($request->hasSession() && $this->session->hasDevice()) {
            return redirect()->route('shared-terminal.kiosk');
        }

        return redirect()->guest(route('login'));
    }
}

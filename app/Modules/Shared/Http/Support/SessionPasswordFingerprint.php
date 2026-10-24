<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Support;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;

/**
 * La huella de la contraseña que una sesión guarda para saber si sigue siendo válida (D372, D381, D382).
 *
 * Cada guardia con sesión guarda, bajo `password_hash_{guardia}`, el HMAC del cifrado de la contraseña con que entró. Si
 * la contraseña cambia, la huella deja de coincidir y la sesión cae en su siguiente petición: así un cambio de contraseña
 * saca a quien tenga otra sesión abierta.
 *
 * Es el formato de `AuthenticateSession` de Laravel y de Sanctum (HMAC con la llave de la app, con respaldo al cifrado en
 * crudo de las sesiones viejas), para que todas las revisiones lean la misma llave igual. La usan `AuthenticateWebSession`
 * (personal), `EnsureSuperAdmin` (plataforma) y `CustomerSession` (clientes de la tienda); cada una decide qué hacer
 * cuando no coincide.
 */
final class SessionPasswordFingerprint
{
    public function key(string $guardName): string
    {
        return 'password_hash_'.$guardName;
    }

    /**
     * Guarda la huella de la contraseña vigente del usuario del guardia.
     */
    public function store(Request $request, SessionGuard $guard, string $guardName): void
    {
        $request->session()->put(
            $this->key($guardName),
            $guard->hashPasswordForCookie((string) $guard->user()?->getAuthPassword()),
        );
    }

    /**
     * ¿La sesión trae la huella de esta contraseña? Una sesión sin huella —de antes de que su guardia la revisara— adopta
     * la vigente, como `AuthenticateSession`: no se saca a nadie por desplegar.
     */
    public function sessionMatches(Request $request, SessionGuard $guard, string $guardName, string $cifrado): bool
    {
        $llave = $this->key($guardName);

        if (! $request->session()->has($llave)) {
            $this->store($request, $guard, $guardName);
        }

        return $this->matches($guard, $cifrado, (string) $request->session()->get($llave));
    }

    /**
     * ¿La cookie de «Mantener la sesión abierta» se emitió con esta contraseña? La cookie lleva el cifrado en su tercer
     * segmento.
     */
    public function recallerMatches(Request $request, SessionGuard $guard, string $cifrado): bool
    {
        $delaCookie = explode('|', (string) $request->cookies->get($guard->getRecallerName()))[2] ?? null;

        return $delaCookie !== null && $this->matches($guard, $cifrado, $delaCookie);
    }

    public function matches(SessionGuard $guard, string $cifrado, string $guardado): bool
    {
        return hash_equals($guard->hashPasswordForCookie($cifrado), $guardado) || hash_equals($cifrado, $guardado);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Principal MÍNIMO para satisfacer el gate `auth:sanctum` de una terminal compartida (ADR-012).
 *
 * ## Qué es y qué NO es
 *
 * `auth:sanctum` exige que la petición tenga un principal autenticado, y para una petición de
 * dispositivo (sesión sin usuario) no hay `User`. Este objeto es sólo eso: un principal que hace
 * pasar el gate, portando el id de la membresía operadora. **No es la identidad de negocio.** La
 * autorización (`Authorize`) y la atribución NO lo leen a él: leen el `RequestContext` (holder), que
 * el middleware arma con la membresía y el rol activos.
 *
 * Deliberadamente NO es un modelo ni usa `HasApiTokens`: Sanctum, al ver que no soporta tokens,
 * lo devuelve tal cual sin intentar envolverlo con un `TransientToken`. Y como NO es un `User`,
 * `ResolveTenantContext` lo ignora (su rama de "no hay usuario" lo deja pasar sin tocar el contexto
 * que ya puso el middleware de terminal compartida).
 */
final class SharedTerminalPrincipal implements Authenticatable
{
    public function __construct(private readonly int $membershipId) {}

    public function membershipId(): int
    {
        return $this->membershipId;
    }

    public function getAuthIdentifierName(): string
    {
        return 'membership_id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->membershipId;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        // No hay contraseña: la identificación fue por PIN sobre un dispositivo ya autenticado.
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // Sin "recordarme": la sesión de operación es corta y caduca por inactividad (ADR-012).
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}

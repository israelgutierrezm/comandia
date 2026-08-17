<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Application\Authorization\Authorize;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de permiso: `->middleware('can:pos.accounts.charge')`.
 *
 * Alias propio y **no** el `can` de Laravel ni el `permission` de Spatie: los dos
 * evalúan la suma de roles del usuario, y aquí opera el rol activo (D9). Registrarlo
 * con el nombre `can` es deliberado —es el nombre que un desarrollador de Laravel va a
 * escribir por instinto—, así que conviene que ese instinto lleve al camino correcto
 * en lugar de al que concede permisos de más.
 *
 * Para acciones de escritura existe `can.write`, que además exige que el tenant admita
 * escrituras (estado `read_only` por impago).
 */
final class EnsurePermission
{
    public function __construct(private readonly Authorize $authorize) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->authorize->authorize($permission);

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Application\Authorization\Authorize;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permiso de escritura: `->middleware('can.write:pos.accounts.charge')`.
 *
 * Además del permiso, exige que el tenant admita escrituras. Un tenant en `read_only`
 * por impago tiene que poder consultar y exportar sus datos —son suyos— y no poder
 * cobrar.
 *
 * Se separa del middleware de lectura para que en el archivo de rutas quede visible
 * qué endpoints escriben. Una bandera booleana en el mismo middleware lo habría
 * ocultado.
 */
final class EnsureWritePermission
{
    public function __construct(private readonly Authorize $authorize) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->authorize->authorizeWrite($permission);

        return $next($request);
    }
}

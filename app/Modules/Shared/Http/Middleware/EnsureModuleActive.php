<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Application\Authorization\ModuleGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verificación de módulo activo por grupo de rutas: `->middleware('module:Ecommerce')`.
 *
 * Es la segunda de las tres barreras que hacen cierta la promesa de
 * ARQUITECTURA_MAESTRA §2 regla 4 —"un tenant sin e-commerce no ejecuta una sola línea
 * de ese módulo"—. Las otras dos son la autorización y el guard de navegación del
 * frontend.
 *
 * Va en el grupo de rutas y no dentro de cada controlador porque la promesa es "no
 * ejecuta su código": rechazar antes de entrar al módulo es lo que cumple la frase al
 * pie de la letra.
 */
final class EnsureModuleActive
{
    public function __construct(private readonly ModuleGate $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $this->modules->authorizeModule($module);

        return $next($request);
    }
}

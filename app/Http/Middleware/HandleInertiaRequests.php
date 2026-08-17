<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Datos compartidos con el shell de Inertia.
 *
 * Frontera deliberada (ver docs/REGISTRO_DECISIONES.md, D59): Inertia entrega
 * únicamente el *shell* de la aplicación —identidad, contexto operativo, flags
 * de módulos activos y permisos del rol activo para pintar la navegación—.
 * Ningún dato transaccional viaja por aquí: órdenes, cuentas, existencias y
 * cortes se consumen desde /api/v1 para que la web y la app Flutter usen
 * exactamente la misma API (ARQUITECTURA_MAESTRA §8).
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name'),
            ],

            // El contexto real {tenant, membresía, rol activo, sucursal activa,
            // terminal} se comparte a partir de la Iteración 1, resuelto por el
            // middleware de tenant. Hasta entonces sólo se expone el usuario
            // autenticado, sin datos de tenant.
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email']),
            ],
        ]);
    }
}

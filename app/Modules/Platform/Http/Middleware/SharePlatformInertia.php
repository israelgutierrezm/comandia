<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

/**
 * Datos compartidos con el shell de Inertia de la PLATAFORMA.
 *
 * Deliberadamente mínimo y aparte del shell de los negocios (`HandleInertiaRequests`): aquí no hay tenant, ni rol
 * activo, ni permisos de negocio, ni acento por negocio. Sólo el super admin autenticado y los mensajes de una
 * navegación a la siguiente.
 */
final class SharePlatformInertia extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var PlatformAdmin|null $admin */
        $admin = Auth::guard('platform')->user();

        return array_merge(parent::share($request), [
            'app' => [
                'name' => config('app.name'),
            ],

            'platform_auth' => $admin === null ? null : [
                'name' => $admin->name,
                'email' => $admin->email,
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}

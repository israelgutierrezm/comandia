<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\PlatformPasswords;
use App\Modules\Platform\Http\Requests\ChangePlatformPasswordRequest;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi cuenta» del super administrador (D382): sus datos y cambiar la contraseña con la sesión abierta.
 *
 * No hay «¿Olvidaste tu contraseña?» público para la plataforma: quien la pierde la recupera por la consola del servidor
 * (`comandia:platform-admin:password`), un factor más fuerte que un buzón. Aquí sólo se cambia sabiendo la actual.
 */
final class PlatformAccountController
{
    public function __construct(private readonly PlatformPasswords $passwords) {}

    public function show(): Response
    {
        $admin = $this->admin();

        return Inertia::render('Platform/Account', [
            'admin' => [
                'name' => $admin->name,
                'email' => $admin->email,
                'last_login_at' => $admin->last_login_at?->toIso8601String(),
            ],
        ]);
    }

    public function updatePassword(ChangePlatformPasswordRequest $request): RedirectResponse
    {
        $this->passwords->change($this->admin(), (string) $request->string('password'), via: 'panel', request: $request);

        return redirect()->route('platform.account')
            ->with('success', 'Contraseña actualizada. Tus otras sesiones se cerraron.');
    }

    private function admin(): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return Auth::guard('platform')->user();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Requests\PlatformLoginRequest;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Shared\Http\Support\SessionPasswordFingerprint;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acceso a la super administración de la plataforma.
 *
 * Superficie APARTE del acceso de negocios: guard propio (`platform`), tabla propia, cookie propia. Un usuario de
 * negocio jamás autentica aquí. El mensaje de error no distingue «no existe» de «contraseña incorrecta», para no
 * revelar qué correos son super admin.
 *
 * Al entrar se guarda la huella de la contraseña: si después cambia, `EnsureSuperAdmin` cierra esta sesión (D382).
 */
final class PlatformLoginController
{
    public function __construct(private readonly SessionPasswordFingerprint $fingerprint) {}

    public function show(): Response|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        return Inertia::render('Platform/Login');
    }

    /**
     * @throws ValidationException
     */
    public function store(PlatformLoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        if (! Auth::guard('platform')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('platform');
        $this->fingerprint->store($request, $guard, 'platform');

        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('platform')->user();
        $admin->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}

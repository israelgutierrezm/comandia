<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inicio y cierre de sesión de la SPA de administración.
 *
 * ## Por qué el tenant NO se elige aquí
 *
 * La autenticación es global al SaaS —el correo es único en toda la plataforma (§4.1)— y sólo
 * después se decide en qué negocio se va a operar. Mezclar las dos cosas obligaría a pedir el
 * negocio antes de saber si la persona existe, y con eso se filtraría qué correos pertenecen a qué
 * negocio a quien pruebe combinaciones.
 *
 * Con una sola membresía activa se selecciona sola: no hay nada que elegir y preguntarlo sería
 * fricción pura.
 */
final class LoginController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        if (! Auth::attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            $request->hitRateLimiter();

            $this->auditFailedLogin($request);

            throw ValidationException::withMessages([
                // Mensaje único para "no existe el correo" y "contraseña incorrecta": distinguirlos
                // permitiría averiguar qué correos están registrados en el SaaS.
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->redirectAfterLogin($request, $user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $tenantId = $request->session()->get('tenant_id');

        if (is_int($tenantId)) {
            // Se audita dentro del contexto del tenant que se abandona: una sesión cerrada es un
            // dato de ese negocio, no del siguiente.
            app(TenantContext::class)->runFor(
                $tenantId,
                fn () => $this->audit->log(action: AuditAction::LOGOUT),
            );
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function redirectAfterLogin(Request $request, User $user): RedirectResponse
    {
        $memberships = $user->membershipsAcrossTenants()
            ->where('status', 'active')
            ->get();

        if ($memberships->isEmpty()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['Tu cuenta no está activa en ningún negocio. Contacta a quien te dio de alta.'],
            ]);
        }

        if ($memberships->count() === 1) {
            /** @var TenantMembership $membership */
            $membership = $memberships->first();

            return $this->enterTenant($request, $membership);
        }

        return redirect()->route('tenants.select');
    }

    private function enterTenant(Request $request, TenantMembership $membership): RedirectResponse
    {
        $tenantId = (int) $membership->tenantId();

        $request->session()->put('tenant_id', $tenantId);

        app(TenantContext::class)->runFor(
            $tenantId,
            // La membresía va como actor explícito: el contexto de esta petición se resolvió cuando
            // todavía no había sesión, así que está vacío, y sin esto el asiento del inicio de sesión
            // quedaba atribuido a «Sistema».
            fn () => $this->audit->log(
                action: AuditAction::LOGIN,
                auditable: $membership,
                actor: $membership,
            ),
        );

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Un intento fallido se registra aunque no se sepa el tenant.
     *
     * Cinco fallos seguidos sobre el mismo correo son la señal, y sin registrarlos no existe (§6.7).
     * Si el correo corresponde a alguien con una sola membresía activa, se anota en ese tenant para
     * que su administrador lo vea; si no, no se puede atribuir a ninguno y sólo queda el registro
     * del limitador de tasa.
     */
    private function auditFailedLogin(LoginRequest $request): void
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null) {
            return;
        }

        $memberships = $user->membershipsAcrossTenants()->where('status', 'active')->get();

        if ($memberships->count() !== 1) {
            return;
        }

        /** @var TenantMembership $membership */
        $membership = $memberships->first();

        app(TenantContext::class)->runFor(
            (int) $membership->tenantId(),
            fn () => $this->audit->log(
                action: AuditAction::LOGIN_FAILED,
                auditable: $membership,
                after: ['email' => $request->string('email')->toString()],
                // Un intento fallido tampoco tiene contexto, y sin actor explícito el reporte de
                // «cinco fallos seguidos sobre esta persona» (§6.7) no puede agrupar por persona.
                actor: $membership,
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Modules\Identity\Application\MembershipInvitations;
use App\Modules\Identity\Application\StartTenantSession;
use App\Modules\Identity\Http\Requests\AcceptInvitationRequest;
use App\Modules\Identity\Infrastructure\Models\MembershipInvitation;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Aceptar una invitación desde el enlace del correo (diseño de acceso, fase 3).
 *
 * Pública: el enlace llega desde fuera y es el TOKEN lo que dice de qué negocio es (`MembershipInvitations::findByToken`).
 * Quién acepta decide lo que se pide:
 *
 * - **Sin cuenta:** su nombre y la contraseña que va a usar. Se crea la cuenta con el correo ya verificado —el enlace
 *   llegó a él— y se entra al negocio.
 * - **Con cuenta y sin sesión:** su contraseña de siempre. Es el consentimiento que faltaba cuando el alta la sumaba sola.
 * - **Con la sesión abierta con ese correo:** sólo «Aceptar».
 * - **Con la sesión de OTRA persona:** se pide salir primero. Nunca se liga una invitación a quien no es su destinatario.
 *
 * Tras aceptar, la persona queda dentro del negocio que la invitó, como si hubiera entrado por el acceso.
 */
final class InvitationAcceptanceController
{
    public function __construct(
        private readonly MembershipInvitations $invitations,
        private readonly StartTenantSession $session,
        private readonly TenantContext $tenants,
    ) {}

    public function show(string $token): Response
    {
        $invitacion = $this->invitations->findByToken($token);

        $estado = $this->state($invitacion);

        if ($invitacion === null || $estado !== 'pending') {
            return Inertia::render('Auth/AcceptInvitation', [
                'token' => $token,
                'state' => $estado,
                'business' => $invitacion?->tenant?->name,
            ]);
        }

        [$roles, $nombre] = $this->tenants->runFor((int) $invitacion->tenant_id, function () use ($invitacion): array {
            $persona = TenantMembership::query()->with('employeeProfile')->find($invitacion->membership_id);

            return [
                $invitacion->roles()->pluck('name')->values()->all(),
                [
                    'first_name' => $persona?->employeeProfile?->legal_first_name,
                    'paternal_surname' => $persona?->employeeProfile?->legal_paternal_surname,
                    'maternal_surname' => $persona?->employeeProfile?->legal_maternal_surname,
                ],
            ];
        });

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'state' => 'pending',
            'mode' => $this->mode($invitacion),
            'business' => $invitacion->tenant?->name,
            'email' => $invitacion->email,
            'roles' => $roles,
            'suggested_name' => $nombre,
            'expires_at' => $invitacion->expires_at->toIso8601String(),
            'signed_in_as' => $this->signedIn()?->email,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $request->throttle();

        $invitacion = $this->invitations->findByToken($token);

        if ($invitacion === null || ! $invitacion->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => ['Esta invitación ya no vale: venció, ya se usó o la cancelaron. Pide otra a quien te invitó.'],
            ]);
        }

        $sesion = $this->signedIn();

        if ($sesion !== null && mb_strtolower((string) $sesion->email) !== mb_strtolower($invitacion->email)) {
            throw ValidationException::withMessages([
                'invitation' => [sprintf('Estás dentro como %s. Sal de esa cuenta para aceptar esta invitación.', $sesion->email)],
            ]);
        }

        try {
            [$cuenta, $membresia] = DB::transaction(function () use ($request, $invitacion, $sesion): array {
                $cuenta = $sesion ?? $this->accountFor($request, $invitacion);

                return [$cuenta, $this->invitations->accept($invitacion, $cuenta)];
            });
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['invitation' => [$e->getMessage()]]);
        }

        if ($sesion === null) {
            Auth::guard('web')->login($cuenta);
            $request->session()->regenerate();
        }

        $cuenta->forceFill(['last_login_at' => CarbonImmutable::now()])->save();

        $this->session->enter($request, $membresia);

        return redirect()->route('admin.dashboard')
            ->with('success', sprintf('Ya estás dentro de %s.', (string) $invitacion->tenant?->name));
    }

    /**
     * La cuenta de quien acepta sin sesión: la que ya tenía (con su contraseña) o una nueva (con la que elige).
     *
     * @throws ValidationException
     */
    private function accountFor(AcceptInvitationRequest $request, MembershipInvitation $invitacion): User
    {
        $existente = User::query()->where('email', $invitacion->email)->first();

        if ($existente !== null) {
            if (! Hash::check($request->string('password')->toString(), (string) $existente->password)) {
                throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
            }

            return $existente;
        }

        $nueva = User::create([
            'first_name' => $request->string('first_name')->toString(),
            'paternal_surname' => $request->string('paternal_surname')->toString(),
            'maternal_surname' => $request->filled('maternal_surname') ? $request->string('maternal_surname')->toString() : null,
            'email' => $invitacion->email,
            'password' => $request->string('password')->toString(),
        ]);

        // El enlace llegó a ese buzón: es la verificación del correo que hasta hoy nada daba.
        $nueva->forceFill(['email_verified_at' => CarbonImmutable::now()])->save();

        return $nueva;
    }

    /**
     * @return 'invalid'|'used'|'revoked'|'expired'|'pending'
     */
    private function state(?MembershipInvitation $invitacion): string
    {
        return match (true) {
            $invitacion === null => 'invalid',
            $invitacion->accepted_at !== null => 'used',
            $invitacion->revoked_at !== null => 'revoked',
            $invitacion->isExpired() => 'expired',
            default => 'pending',
        };
    }

    /**
     * @return 'signed_in'|'other_session'|'existing_account'|'new_account'
     */
    private function mode(MembershipInvitation $invitacion): string
    {
        $sesion = $this->signedIn();

        if ($sesion !== null) {
            return mb_strtolower((string) $sesion->email) === mb_strtolower($invitacion->email) ? 'signed_in' : 'other_session';
        }

        return User::query()->where('email', $invitacion->email)->exists() ? 'existing_account' : 'new_account';
    }

    private function signedIn(): ?User
    {
        $usuario = Auth::guard('web')->user();

        return $usuario instanceof User ? $usuario : null;
    }
}

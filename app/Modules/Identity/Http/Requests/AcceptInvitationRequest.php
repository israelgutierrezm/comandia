<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\MembershipInvitations;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Aceptar una invitación (diseño de acceso, fase 3). Lo que se pide depende de quién acepta:
 *
 * - **Con la sesión abierta con ese correo:** nada; basta con aceptar.
 * - **Con cuenta y sin sesión:** su contraseña de siempre.
 * - **Sin cuenta:** su nombre y la contraseña que va a usar, dos veces, con el mínimo de la plataforma.
 *
 * Cinco intentos por minuto por IP e invitación: la contraseña de una cuenta existente se prueba aquí, y sin límite esto
 * sería un sitio donde adivinarla.
 */
final class AcceptInvitationRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if (Auth::guard('web')->user() instanceof User) {
            return [];
        }

        $invitacion = app(MembershipInvitations::class)->findByToken((string) $this->route('token'));

        $conCuenta = $invitacion !== null
            && User::query()->where('email', $invitacion->email)->exists();

        if ($conCuenta) {
            return ['password' => ['required', 'string', 'max:255']];
        }

        return [
            'first_name' => ['required', 'string', 'max:60'],
            'paternal_surname' => ['required', 'string', 'max:60'],
            'maternal_surname' => ['nullable', 'string', 'max:60'],
            'password' => ['required', 'string', 'min:'.ChangePasswordRequest::MIN_LENGTH, 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Escribe tu nombre.',
            'paternal_surname.required' => 'Escribe tu primer apellido.',
            'password.required' => 'Escribe tu contraseña.',
            'password.min' => 'La contraseña necesita al menos '.ChangePasswordRequest::MIN_LENGTH.' caracteres.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function throttle(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'password' => [sprintf(
                    'Demasiados intentos. Vuelve a intentarlo en %d segundo(s).',
                    RateLimiter::availableIn($this->throttleKey()),
                )],
            ]);
        }

        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    private function throttleKey(): string
    {
        return 'accept-invitation:'.$this->ip().'|'.hash('sha256', (string) $this->route('token'));
    }
}

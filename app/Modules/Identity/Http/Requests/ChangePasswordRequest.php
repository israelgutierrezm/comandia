<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Cambiar mi contraseña desde «Mi cuenta» (diseño de acceso, fase 2).
 *
 * Diez caracteres o más: es la regla de la PLATAFORMA (decisión 6 del diseño), porque la contraseña es la misma en todos
 * los negocios de la persona y un mínimo por negocio no tendría cuál aplicar. Con límite de intentos por persona sobre la
 * contraseña actual, como el acceso (D55): sin él, una sesión abierta sería un sitio donde adivinarla.
 */
final class ChangePasswordRequest extends FormRequest
{
    public const MIN_LENGTH = 10;

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
        return [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:255', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Escribe tu contraseña actual.',
            'password.required' => 'Escribe la contraseña nueva.',
            'password.min' => 'La contraseña nueva necesita al menos '.self::MIN_LENGTH.' caracteres.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'current_password' => [sprintf(
                'Demasiados intentos. Vuelve a intentarlo en %d segundo(s).',
                RateLimiter::availableIn($this->throttleKey()),
            )],
        ]);
    }

    public function hitRateLimiter(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    private function throttleKey(): string
    {
        return 'change-password:'.(string) $this->user()?->getAuthIdentifier();
    }
}

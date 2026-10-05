<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Guardar la contraseña nueva desde el enlace del correo (diseño de acceso, fase 2).
 *
 * El token es aleatorio de 64 caracteres y se guarda cifrado, así que adivinarlo no es un plan; aun así, cinco intentos
 * por minuto por IP, como toda superficie pública de acceso (D55).
 */
final class ResetPasswordRequest extends FormRequest
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
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:'.ChangePasswordRequest::MIN_LENGTH, 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'Al enlace le falta su clave. Pide otro.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe la contraseña nueva.',
            'password.min' => 'La contraseña nueva necesita al menos '.ChangePasswordRequest::MIN_LENGTH.' caracteres.',
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
                'token' => [sprintf(
                    'Demasiados intentos. Vuelve a intentarlo en %d segundo(s).',
                    RateLimiter::availableIn($this->throttleKey()),
                )],
            ]);
        }

        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    private function throttleKey(): string
    {
        return 'reset-password:'.$this->ip();
    }
}

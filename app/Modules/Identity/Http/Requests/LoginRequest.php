<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Inicio de sesión.
 *
 * El rate limiting va aquí y no sólo en la ruta porque se limita por **correo + IP**, no sólo por
 * IP: sin la parte del correo, un atacante distribuido no tendría freno sobre una cuenta concreta;
 * sin la parte de la IP, alguien podría bloquear la cuenta de otro a propósito probando
 * contraseñas malas hasta agotarle los intentos (D55).
 */
final class LoginRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Captura tu correo.',
            'email.email' => 'El correo no tiene un formato válido.',
            'password.required' => 'Captura tu contraseña.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'el correo', 'password' => 'la contraseña'];
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => [sprintf(
                'Demasiados intentos. Vuelve a intentarlo en %d segundo(s).',
                $seconds,
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
        return 'login:'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}

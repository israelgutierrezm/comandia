<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Acceso a la plataforma. Rate limiting por correo + IP, igual que el acceso de negocios (D55): sin la parte del correo
 * un atacante distribuido no tendría freno sobre una cuenta; sin la IP, alguien podría bloquear la cuenta de otro
 * agotándole los intentos.
 */
final class PlatformLoginRequest extends FormRequest
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
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => [sprintf(
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
        return 'platform-login:'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}

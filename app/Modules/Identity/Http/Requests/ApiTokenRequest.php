<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Acceso por TOKEN para la app (Iteración 9). Es el hermano por token del acceso por sesión de la SPA de Vue: la
 * autenticación es global al SaaS y el token se emite DESDE una membresía (D69), así que si la persona pertenece a
 * varios negocios se pide `tenant_ulid` para saber a cuál.
 *
 * Rate limiting por correo + IP, igual que el acceso web (D55).
 */
final class ApiTokenRequest extends FormRequest
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
            // Con qué aparece el token en la lista de sesiones del usuario: «iPad de la barra», «Tablet cocina».
            'device_name' => ['required', 'string', 'max:120'],
            // Opcional: sólo hace falta cuando la persona pertenece a más de un negocio.
            'tenant_ulid' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Captura tu correo.',
            'password.required' => 'Captura tu contraseña.',
            'device_name.required' => 'Falta el nombre del dispositivo.',
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
        return 'api-token:'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * «Cerrar mis otras sesiones en navegadores» (diseño de acceso, fase 1).
 *
 * Pide la contraseña actual: una sesión robada no puede echar a la dueña de su propia cuenta. Y por lo mismo tiene
 * límite de intentos por persona, como el acceso (D55): sin él, este endpoint sería un sitio donde probar contraseñas
 * desde una sesión ya abierta.
 */
final class CloseOtherWebSessionsRequest extends FormRequest
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
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Escribe tu contraseña actual.',
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
            'password' => [sprintf(
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
        return 'close-other-web:'.(string) $this->user()?->getAuthIdentifier();
    }
}

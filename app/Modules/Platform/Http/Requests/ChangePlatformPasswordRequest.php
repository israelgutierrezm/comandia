<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Cambiar la contraseña de un super administrador desde «Mi cuenta» (D382).
 *
 * Pide la contraseña actual —una sesión olvidada abierta no basta para quedarse con la cuenta—, el mínimo del personal
 * (10) y que la nueva sea distinta. Cinco intentos por minuto por admin: la contraseña actual no se adivina desde aquí.
 *
 * Al pasarse del límite responde como el acceso de plataforma, con el mensaje bajo el campo: es un formulario de Inertia,
 * y una página de error 429 lo dejaría sin forma de seguir.
 */
final class ChangePlatformPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'current_password:platform'],
            'password' => ['required', 'string', 'min:'.ChangePasswordRequest::MIN_LENGTH, 'max:255', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Captura tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Captura la contraseña nueva.',
            'password.min' => 'La contraseña nueva necesita al menos '.ChangePasswordRequest::MIN_LENGTH.' caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ];
    }

    /**
     * Cuenta el intento ANTES de validar: así también cuentan los de contraseña actual equivocada.
     *
     * @throws ValidationException
     */
    protected function prepareForValidation(): void
    {
        $llave = 'platform-password:'.Auth::guard('platform')->user()?->ulid;

        if (RateLimiter::tooManyAttempts($llave, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current_password' => [sprintf(
                    'Demasiados intentos. Vuelve a intentarlo en %d segundo(s).',
                    RateLimiter::availableIn($llave),
                )],
            ]);
        }

        RateLimiter::hit($llave, self::DECAY_SECONDS);
    }
}

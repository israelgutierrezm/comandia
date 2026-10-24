<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Http\Concerns\ThrottlesStoreRequests;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Crear la contraseña nueva con el enlace del correo (D381).
 *
 * El token no se valida aquí más que como texto: uno mal formado responde lo mismo que uno vencido o usado —el 422 único
 * de `CustomerPasswordResetInvalid`—, para no decirle nada a quien prueba tokens. El mínimo es el del registro.
 */
final class ResetCustomerPasswordRequest extends FormRequest
{
    use ThrottlesStoreRequests;

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
            'password' => ['required', 'string', 'min:'.RegisterCustomerRequest::MIN_PASSWORD_LENGTH, 'max:200', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['password' => 'contraseña', 'token' => 'el enlace'];
    }

    /**
     * Cuenta el intento y rechaza si ya pasó de 5 en un minuto desde la misma conexión.
     */
    public function throttle(): void
    {
        $this->consumeAttempt('customer-reset:'.$this->ip(), self::MAX_ATTEMPTS, self::DECAY_SECONDS, 'Demasiados intentos.');
    }
}

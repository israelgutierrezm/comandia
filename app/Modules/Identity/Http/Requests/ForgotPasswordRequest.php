<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Shared\Http\Support\EmailThrottleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Pedir el enlace para crear una contraseña nueva (diseño de acceso, fase 2).
 *
 * Tres pedidos por correo e IP cada 15 minutos, además del minuto entre enlaces que ya impone el broker. Cuenta TODOS
 * los pedidos, exista o no la cuenta: contar sólo los de cuentas existentes diría cuáles existen.
 */
final class ForgotPasswordRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 900;

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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
        ];
    }

    /**
     * Cuenta el pedido y rechaza si con él se pasa del tope. La cuenta es atómica (`hit` devuelve la nueva): leer y sumar
     * por separado deja pasar a la vez a los pedidos simultáneos (D387).
     *
     * @throws ValidationException
     */
    public function throttle(): void
    {
        if (RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS) <= self::MAX_ATTEMPTS) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => [sprintf(
                'Ya pediste varios enlaces. Espera %d minuto(s) y revisa también la carpeta de no deseado.',
                (int) ceil(RateLimiter::availableIn($this->throttleKey()) / 60),
            )],
        ]);
    }

    /**
     * Con el correo como lo compara la base (`EmailThrottleKey`): si no, cada variante de `ema@…` pediría sus propios tres
     * enlaces para el mismo buzón.
     */
    private function throttleKey(): string
    {
        return 'forgot-password:'.EmailThrottleKey::of((string) $this->input('email')).'|'.$this->ip();
    }
}

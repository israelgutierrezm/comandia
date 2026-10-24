<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Http\Concerns\ThrottlesStoreRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Inicio de sesión de un cliente en la tienda en línea (Iteración 8, Tanda C).
 *
 * Límite por negocio + correo + IP (D381): cinco intentos por minuto, y entrar bien limpia el contador. Sin el correo, un
 * ataque repartido no tendría freno sobre una cuenta; sin la IP, cualquiera bloquearía la cuenta de otro agotándole los
 * intentos. El intento se cuenta antes de evaluar la contraseña (ver `ThrottlesStoreRequests`).
 */
final class LoginCustomerRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string'],
        ];
    }

    public function consumeLoginAttempt(int $tenantId): void
    {
        $this->consumeAttempt($this->throttleKey($tenantId), self::MAX_ATTEMPTS, self::DECAY_SECONDS, 'Demasiados intentos de acceso.');
    }

    public function clearRateLimiter(int $tenantId): void
    {
        RateLimiter::clear($this->throttleKey($tenantId));
    }

    private function throttleKey(int $tenantId): string
    {
        return sprintf('customer-login:%d|%s|%s', $tenantId, $this->throttleEmail(), $this->ip());
    }
}

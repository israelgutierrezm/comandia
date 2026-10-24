<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Http\Concerns\ThrottlesStoreRequests;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «¿Olvidaste tu contraseña?» en la tienda (D381).
 *
 * Dos límites que cuentan TODO pedido, exista o no la cuenta (si sólo contaran los de cuentas reales, el 429 diría cuáles
 * existen): 3 cada 15 minutos por negocio + correo + IP, para no bombardear un buzón ni quemar la reputación del SMTP del
 * negocio; y 20 por hora por IP, para no barrer correos.
 */
final class ForgotCustomerPasswordRequest extends FormRequest
{
    use ThrottlesStoreRequests;

    private const MAX_PER_EMAIL = 3;

    private const EMAIL_DECAY_SECONDS = 900;

    private const MAX_PER_IP = 20;

    private const IP_DECAY_SECONDS = 3600;

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
        ];
    }

    /**
     * Cuenta el pedido y rechaza si ya pasó de alguno de los dos topes.
     */
    public function throttle(int $tenantId): void
    {
        $porCorreo = sprintf('customer-forgot:%d|%s|%s', $tenantId, $this->throttleEmail(), $this->ip());
        $porConexion = 'customer-forgot-ip:'.$this->ip();

        $this->consumeAttempt($porCorreo, self::MAX_PER_EMAIL, self::EMAIL_DECAY_SECONDS, 'Ya pediste varios enlaces para ese correo.');
        $this->consumeAttempt($porConexion, self::MAX_PER_IP, self::IP_DECAY_SECONDS, 'Demasiados pedidos desde esta conexión.');
    }
}

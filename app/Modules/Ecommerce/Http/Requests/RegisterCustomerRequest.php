<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Http\Concerns\ThrottlesStoreRequests;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un cliente en la tienda en línea (Iteración 8, Tanda C). La unicidad de correo y teléfono se verifica en el
 * controlador, ya con el negocio resuelto por el slug (son únicos POR negocio, no globalmente).
 *
 * Tope de 10 registros por hora por IP (D381): el registro todavía dice si un correo o un teléfono ya tiene cuenta
 * (pendiente declarado), y sin tope serviría para barrer correos.
 */
final class RegisterCustomerRequest extends FormRequest
{
    use ThrottlesStoreRequests;

    public const MIN_PASSWORD_LENGTH = 8;

    private const MAX_ATTEMPTS = 10;

    private const DECAY_SECONDS = 3600;

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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH, 'max:200'],
        ];
    }

    /**
     * Cuenta el intento y rechaza si ya pasó del tope.
     */
    public function throttle(): void
    {
        $this->consumeAttempt('customer-register:'.$this->ip(), self::MAX_ATTEMPTS, self::DECAY_SECONDS, 'Demasiados registros desde esta conexión.');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos del checkout (Iteración 8, Tanda C parte 2). El carrito y el cliente ya están en la sesión; aquí sólo la entrega.
 * La zona y la dirección se exigen para envío; el servicio los vuelve a validar.
 */
final class CheckoutRequest extends FormRequest
{
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
            'delivery_type' => ['required', 'in:pickup,shipping'],
            'zone_ulid' => ['nullable', 'required_if:delivery_type,shipping', 'string', 'size:26'],
            'address' => ['nullable', 'required_if:delivery_type,shipping', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }
}

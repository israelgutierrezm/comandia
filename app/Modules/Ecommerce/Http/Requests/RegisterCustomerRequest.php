<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un cliente en la tienda en línea (Iteración 8, Tanda C). La unicidad de correo y teléfono se verifica en el
 * controlador, ya con el negocio resuelto por el slug (son únicos POR negocio, no globalmente).
 */
final class RegisterCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }
}

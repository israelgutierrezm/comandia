<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Inicio de sesión de un cliente en la tienda en línea (Iteración 8, Tanda C).
 */
final class LoginCustomerRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string'],
        ];
    }
}

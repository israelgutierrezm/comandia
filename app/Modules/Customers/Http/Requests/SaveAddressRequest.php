<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de una dirección de cliente (§6.6). En columnas, sin JSON.
 */
final class SaveAddressRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:60'],
            'street' => ['required', 'string', 'max:160'],
            'exterior_number' => ['required', 'string', 'max:30'],
            'interior_number' => ['nullable', 'string', 'max:30'],
            'neighborhood' => ['required', 'string', 'max:120'],
            'municipality' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'regex:/^[0-9]{5}$/'],
            // País fijo MX en v1: el negocio es mexicano y el CFDI lo asume. Se acepta el campo por si el modelo futuro
            // lo necesita, pero se valida a MX.
            'country' => ['nullable', 'string', 'in:MX'],
            'reference' => ['nullable', 'string', 'max:200'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'postal_code.regex' => 'El código postal son 5 dígitos.',
        ];
    }
}

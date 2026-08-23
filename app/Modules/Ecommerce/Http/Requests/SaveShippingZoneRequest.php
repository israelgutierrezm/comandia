<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Crea o edita una zona de envío. El costo es DECIMAL(12,2) y suma al total del pedido.
 */
final class SaveShippingZoneRequest extends FormRequest
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
            'cost' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}

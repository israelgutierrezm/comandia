<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Completar una producción.
 *
 * `produced_quantity` es OPCIONAL y por omisión es la planeada. Existe porque se planean veinte litros y salen
 * dieciocho: sin poder declarar lo que de verdad salió, o se registra una mentira o no se registra nada. Es la misma
 * distinción entre planeado y real que la transferencia hace con sus tres cantidades (D187).
 */
final class CompleteProductionOrderRequest extends FormRequest
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
            'produced_quantity' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'produced_quantity.gt' => 'La cantidad producida tiene que ser mayor que cero. Si no salió nada, cancela '
                .'la orden en lugar de completarla.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['produced_quantity' => 'la cantidad producida'];
    }
}

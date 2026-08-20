<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirar efectivo de la caja.
 *
 * El motivo es OBLIGATORIO, con el mismo argumento que en las mermas (D27): un retiro sin motivo es dinero que salió del
 * cajón y nadie puede explicar. Y el retiro exige PIN siempre, sin umbral — §6.3 lo pone en la lista de acciones
 * sensibles sin excepción de monto, porque un retiro pequeño no es un vaso roto: es dinero saliendo.
 */
final class WithdrawCashRequest extends FormRequest
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
            // Mayor que cero: un retiro de cero no es un retiro. Hay un CHECK en la tabla además de esta regla.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],

            'reason' => ['required', 'string', 'min:3', 'max:300'],

            'authorization_token' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Di para qué se retira el dinero: un retiro sin motivo es dinero que salió del cajón y nadie puede explicar.',
            'reason.min' => 'El motivo tiene que decir algo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['amount' => 'el monto', 'reason' => 'el motivo', 'authorization_token' => 'la autorización'];
    }
}

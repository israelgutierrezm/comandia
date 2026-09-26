<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fija el límite de crédito de un cliente y si lo puede usar.
 *
 * Son dos datos y no uno: un cliente que se atrasó no pierde su límite, pierde el permiso de usarlo. Por eso viajan
 * juntos y ninguno se deduce del otro. El límite lleva el mismo tope y los mismos centavos que el resto de los importes
 * de la API.
 */
final class UpdateCustomerCreditRequest extends FormRequest
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
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credit_limit.decimal' => 'El límite de crédito admite hasta dos decimales.',
            'is_enabled.boolean' => 'Indica si el crédito del cliente queda habilitado o suspendido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credit_limit' => 'el límite de crédito',
            'is_enabled' => 'el estado del crédito',
        ];
    }
}

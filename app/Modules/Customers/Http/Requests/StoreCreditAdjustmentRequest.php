<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Application\AdjustCustomerCredit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Un ajuste al saldo de crédito de un cliente (D378).
 *
 * El sentido va explícito —`decrease` condona o corrige a la baja; `increase` corrige a la alza— y el monto siempre en
 * positivo: un signo tecleado de más convertiría una condonación en una deuda nueva.
 */
final class StoreCreditAdjustmentRequest extends FormRequest
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
            'direction' => ['required', 'string', Rule::in([AdjustCustomerCredit::INCREASE, AdjustCustomerCredit::DECREASE])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],

            // Opcional en la PRIMERA vuelta: sin él, el servidor responde 409 con el permiso que hace falta y la pantalla
            // pide el PIN (ADR-008).
            'authorization_token' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'direction.in' => 'Indica si el ajuste baja lo que debe o lo sube.',
            'amount.gt' => 'El monto del ajuste tiene que ser mayor que cero.',
            'amount.decimal' => 'El monto del ajuste admite hasta dos decimales.',
            'reason.required' => 'Escribe el motivo del ajuste: sin él, nadie puede explicar por qué cambió lo que debe.',
            'reason.min' => 'El motivo del ajuste es demasiado corto para explicarlo.',
            'idempotency_key.required' => 'Falta la llave de la operación. Vuelve a abrir el formulario.',
            'idempotency_key.regex' => 'La llave de la operación sólo admite letras, números, guiones y guiones bajos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'direction' => 'el sentido del ajuste',
            'amount' => 'el monto',
            'reason' => 'el motivo',
            'idempotency_key' => 'la llave de la operación',
            'authorization_token' => 'la autorización',
        ];
    }
}

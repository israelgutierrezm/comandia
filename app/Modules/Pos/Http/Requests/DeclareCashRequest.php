<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Declarar lo que hay en caja: precorte o cierre.
 *
 * Una declaración POR MÉTODO de pago, y no un total: el cajero cuenta el efectivo y mira el corte de la terminal
 * bancaria por separado. Un único total mezclado no serviría para saber DÓNDE falta dinero, que es la única pregunta
 * útil cuando un corte no cuadra.
 */
final class DeclareCashRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'moment' => ['required', Rule::in(['precount', 'close'])],

            'declarations' => ['required', 'array', 'min:1', 'max:50'],

            'declarations.*.payment_method_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('payment_methods', 'ulid')->where('tenant_id', $tenantId),
            ],

            // Cero es una declaración legítima y necesaria: «de tarjeta no entró nada» es información, y omitir el
            // método dejaría la duda de si nadie lo contó.
            'declarations.*.declared_amount' => ['required', 'numeric', 'gte:0', 'max:9999999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'moment.in' => 'El momento sólo puede ser el precorte o el cierre.',
            'declarations.required' => 'Declara al menos un método de pago: sin declaración no hay arqueo posible.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['moment' => 'el momento', 'declarations' => 'las declaraciones'];
    }
}

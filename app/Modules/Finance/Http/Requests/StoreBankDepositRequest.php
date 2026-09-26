<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar un depósito bancario: el efectivo retirado del cajón que llegó al banco.
 */
final class StoreBankDepositRequest extends FormRequest
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
            'branch_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
            'bank_name' => ['required', 'string', 'min:2', 'max:60'],

            // El folio del comprobante, obligatorio: sin él no se puede buscar en el estado de cuenta, que es lo único
            // para lo que sirve registrarlo.
            'reference' => ['required', 'string', 'min:1', 'max:60'],

            // No puede ser futura: un depósito que todavía no ocurrió no es un depósito.
            'deposited_on' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'deposited_on.before_or_equal' => 'La fecha del depósito no puede ser futura.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'la sucursal',
            'amount' => 'el importe',
            'bank_name' => 'el banco',
            'reference' => 'la referencia del comprobante',
            'deposited_on' => 'la fecha del depósito',
        ];
    }
}

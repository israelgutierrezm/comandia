<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrar un gasto.
 */
final class StoreExpenseRequest extends FormRequest
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
            'branch_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            'expense_category_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('expense_categories', 'ulid')->where('tenant_id', $tenantId),
            ],

            'source' => ['required', 'string', 'in:cash_session,outside_cash'],

            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],

            // Obligatoria y con mínimo: «¿en qué se fue ese dinero?» es la pregunta del arqueo, y una categoría sola no
            // la contesta. «Gastos varios: $800» no explica nada.
            'description' => ['required', 'string', 'min:3', 'max:300'],

            // Sólo para los de FUERA de caja: hay que decir por dónde salió el dinero.
            'payment_method_ulid' => [
                'nullable', 'required_if:source,outside_cash', 'string', 'size:26',
                Rule::exists('payment_methods', 'ulid')->where('tenant_id', $tenantId)->where('status', 'active'),
            ],

            // El comprobante es OPCIONAL (§6.5): exigirlo haría que el gasto de 40 pesos de hielo no se registrara, y un
            // gasto sin comprobante es infinitamente mejor que un gasto sin registrar.
            'receipt_path' => ['nullable', 'string', 'max:300'],

            'authorization_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'sucursal',
            'expense_category_ulid' => 'categoría',
            'source' => 'origen del dinero',
            'amount' => 'monto',
            'description' => 'descripción',
        ];
    }
}

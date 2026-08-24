<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea o edita un cupón (Iteración 8, Tanda D, D3). El código es único por negocio (ignorando el propio al editar) y el
 * valor se valida según el tipo, igual que el `CHECK` de la base: porcentaje 1–100, monto fijo positivo, envío gratis sin
 * valor (se normaliza a cero).
 */
final class SaveCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // El envío gratis no lleva valor; se fija en cero para cumplir el CHECK y no depender de que el cliente lo mande.
        if ($this->input('type') === 'free_shipping') {
            $this->merge(['value' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'alpha_dash', 'max:40',
                Rule::unique('coupons', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($this->route('coupon')),
            ],
            'type' => ['required', Rule::in(['percentage', 'fixed', 'free_shipping'])],
            'value' => match ((string) $this->input('type')) {
                'percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
                'fixed' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                default => ['required', 'numeric', 'in:0'], // free_shipping
            },
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}

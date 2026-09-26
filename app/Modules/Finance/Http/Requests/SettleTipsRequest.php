<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Entregarle a alguien su propina (§6.6).
 */
final class SettleTipsRequest extends FormRequest
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
            'membership_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('tenant_memberships', 'ulid')->where('tenant_id', $tenantId),
            ],

            // De qué cajón sale el efectivo.
            'branch_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            // El monto lo manda quien liquida —puede entregar una parte— y el servidor comprueba que no pase del
            // disponible, recalculado dentro de la transacción.
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'membership_ulid' => 'la persona',
            'branch_ulid' => 'la sucursal',
            'amount' => 'el importe',
        ];
    }
}

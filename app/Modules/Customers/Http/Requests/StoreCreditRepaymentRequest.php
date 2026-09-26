<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Application\RegisterCreditRepayment;
use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Un abono a la cuenta de un cliente (§6.3).
 *
 * ## Con qué se puede abonar
 *
 * Con cualquier método ACTIVO del negocio, salvo el propio crédito del cliente: pagar lo que se debe fiando más no es un
 * abono. La regla vive en `RegisterCreditRepayment` —la única puerta de los abonos, que la vuelve a comprobar para quien
 * no llega por HTTP— y aquí sólo se traduce a un 422 sobre el campo, con el mismo motivo.
 *
 * ## La sucursal: aquí sólo que exista
 *
 * Que sea del negocio lo dice esta validación; que quien cobra OPERE en ella lo comprueba el controlador con
 * `assertBranchInScope`, porque la sucursal ajena es del mismo negocio y pasaría por aquí entera.
 */
final class StoreCreditRepaymentRequest extends FormRequest
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

            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],

            'payment_method_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('payment_methods', 'ulid')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('payment_method_ulid')) {
                    return;
                }

                $metodo = PaymentMethod::query()
                    ->where('ulid', (string) $this->string('payment_method_ulid'))
                    ->first();

                if ($metodo === null) {
                    return;
                }

                try {
                    RegisterCreditRepayment::ensureAcceptsMethod($metodo);
                } catch (CreditInvariantException $e) {
                    $validator->errors()->add('payment_method_ulid', $e->getMessage());
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.exists' => 'La sucursal no existe en el negocio.',
            'payment_method_ulid.exists' => 'El método de pago no existe en el negocio.',
            'amount.gt' => 'El monto del abono tiene que ser mayor que cero.',
            'amount.decimal' => 'El monto del abono admite hasta dos decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'la sucursal',
            'amount' => 'el monto',
            'payment_method_ulid' => 'el método de pago',
        ];
    }
}

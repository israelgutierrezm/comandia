<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cobrar una cuenta, con una o varias líneas de pago.
 *
 * ## Lo que el cliente SÍ manda, y por qué es distinto del precio
 *
 * El monto de cada línea viene del cliente, a diferencia del precio de un artículo (§6.9). No es una inconsistencia: el
 * precio es un dato del negocio que el servidor conoce, y **cuánto pone el cliente en efectivo no lo sabe nadie más que
 * quien está en la caja**. Lo que el servidor sí impone es que la suma no puede inventarse: el cambio se calcula aquí,
 * la propina se congela con nombre y la cuenta sólo pasa a pagada cuando lo aplicado cubre el total.
 */
final class ChargeAccountRequest extends FormRequest
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
            'version' => ['sometimes', 'integer', 'min:0'],

            // Cinco líneas: pagar con más de tres métodos a la vez no ocurre, y un tope alto sólo daría margen a un
            // cliente que mande basura.
            'payments' => ['required', 'array', 'min:1', 'max:5'],

            'payments.*.payment_method_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('payment_methods', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active'),
            ],

            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],

            // Lo que el cliente puso sobre la barra. Sólo tiene sentido en efectivo, y el servicio decide si da cambio
            // según el método — validarlo aquí exigiría consultar el método, que es justo lo que el servicio ya hace.
            'payments.*.tendered_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],

            'payments.*.tip_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],

            // A quién se le atribuye. Si no viene, es el titular de la cuenta (D233).
            'payments.*.tip_membership_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('tenant_memberships', 'ulid')->where('tenant_id', $tenantId),
            ],

            'payments.*.reference' => ['nullable', 'string', 'max:60'],

            // Si el cliente pide factura, el perfil fiscal que eligió. El servicio valida que sea de este cliente y
            // congela su snapshot en el ticket (D317). Opcional: la mayoría de las ventas son público en general.
            'fiscal_profile_ulid' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payments' => 'líneas de pago',
            'payments.*.amount' => 'monto',
            'payments.*.tendered_amount' => 'monto entregado',
            'payments.*.tip_amount' => 'propina',
        ];
    }
}

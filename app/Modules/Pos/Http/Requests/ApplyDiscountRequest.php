<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aplicar un descuento o una cortesía.
 *
 * ## Lo que NO se manda: el monto
 *
 * El cliente manda el TIPO y el VALOR —«10 %», «50 pesos», «cortesía»— y nunca el resultado. Si mandara el monto, un
 * «10 %» podría llegar como cualquier cifra desde la consola del navegador. §6.9 lo dice en general —el frontend
 * previsualiza, el backend decide— y aquí es donde más caro sale ignorarlo.
 */
final class ApplyDiscountRequest extends FormRequest
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

            'kind' => ['required', 'string', 'in:percentage,amount,courtesy'],

            // No se pide en una cortesía —es la línea entera— y el servicio lo ignora. Se acepta nulo aquí para que el
            // cliente no tenga que mandar un valor inventado.
            'value' => ['nullable', 'required_unless:kind,courtesy', 'numeric', 'gt:0', 'max:9999999.99', 'decimal:0,2'],

            // El motivo, obligatorio. Mismo argumento que las mermas (D27): un descuento sin motivo es dinero que nadie
            // puede explicar. Y `min:3` porque un motivo de un carácter es no poner motivo.
            'reason' => ['required', 'string', 'min:3', 'max:300'],

            // `null` = descuento de la cuenta entera.
            'item_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('pos_order_items', 'ulid')->where('tenant_id', $tenantId),
            ],

            'authorization_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo de descuento',
            'value' => 'valor',
            'reason' => 'motivo',
            'item_ulid' => 'item',
        ];
    }
}

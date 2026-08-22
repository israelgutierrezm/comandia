<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un cliente.
 *
 * ## Sólo el nombre es obligatorio (D43)
 *
 * Todo lo demás es opcional a propósito. El alta express desde el POS es el caso normal: alguien está pagando y hay que
 * registrarlo en dos toques. Pedir más haría que nadie registrara clientes, y sin clientes el crédito no existe.
 */
final class SaveCustomerRequest extends FormRequest
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
        $customer = $this->route('customer');

        $unico = fn (string $campo) => Rule::unique('customers', $campo)
            ->where('tenant_id', $tenantId)
            ->ignore($customer?->id);

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // El teléfono es el identificador del mostrador: dos clientes con el mismo son un error de captura que
            // acaba con el saldo de uno cargado al otro.
            'phone' => ['nullable', 'string', 'max:20', $unico('phone')],

            'code' => ['nullable', 'string', 'max:20', $unico('code')],
            'email' => ['nullable', 'email', 'max:120'],

            // Cumpleaños (D43): fecha completa para «cumpleañeros del mes» y felicitaciones. No puede ser futura —nadie
            // nace mañana— y nadie tiene 130 años.
            'birthday' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],

            'notes' => ['nullable', 'string', 'max:300'],

            // Sólo al editar: un cliente nace activo.
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'phone' => 'teléfono', 'code' => 'código'];
    }
}

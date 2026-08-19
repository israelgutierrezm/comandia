<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de un proveedor.
 *
 * **El código no está aquí**, a propósito: es el identificador con el que la gente lo llama en papeles y
 * conversaciones, y reasignarlo haría que los documentos viejos parecieran ser de otro proveedor. El modelo también lo
 * bloquea; esto sólo evita que la petición lo intente y reciba un 500 en lugar de un mensaje.
 *
 * Todo lo demás se corrige, incluido el RFC: se teclea mal, y corregirlo no reinterpreta ninguna compra pasada —lo que
 * la compra cita es el proveedor, no su RFC.
 */
final class UpdateSupplierRequest extends FormRequest
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

        /** @var Supplier $supplier */
        $supplier = $this->route('supplier');

        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:120'],

            'rfc' => [
                'nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                Rule::unique('suppliers', 'rfc')
                    ->where('tenant_id', $tenantId)
                    ->ignore($supplier->id),
            ],

            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Se da de BAJA, no se borra: sus recepciones y su historial de precios lo citan, y un proveedor borrado
            // dejaría compras sin poder decir a quién se le compraron. Por eso no hay endpoint de borrado.
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rfc.regex' => 'Ese RFC no tiene la forma correcta: 12 caracteres para persona moral, 13 para física.',
            'rfc.unique' => 'Ya hay otro proveedor con ese RFC.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_name' => 'la razón social',
            'trade_name' => 'el nombre comercial',
            'rfc' => 'el RFC',
            'contact_name' => 'el contacto',
            'phone' => 'el teléfono',
            'email' => 'el correo',
            'payment_terms_days' => 'los días de crédito',
            'notes' => 'las notas',
            'status' => 'el estado',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('rfc')) {
            $rfc = mb_strtoupper(trim((string) $this->input('rfc')));
            $normalized['rfc'] = $rfc === '' ? null : $rfc;
        }

        foreach (['trade_name', 'contact_name', 'phone', 'email', 'notes'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}

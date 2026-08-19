<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un proveedor (D26).
 *
 * El **RFC se normaliza a `null` cuando llega vacío**, y no es cosmética: la columna es única por negocio, y MySQL
 * admite muchos `NULL` pero no dos cadenas vacías. Sin normalizar, el segundo proveedor sin RFC sería rechazado por
 * duplicado — un error incomprensible para quien sólo dejó el campo en blanco.
 */
final class StoreSupplierRequest extends FormRequest
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
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('suppliers', 'code')->where('tenant_id', $tenantId),
            ],

            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:120'],

            // Forma de RFC, no validez fiscal: 12 caracteres para persona moral, 13 para física. Validar el dígito
            // verificador exigiría implementar el algoritmo del SAT, y un RFC bien formado pero inexistente se
            // descubre igual al facturar. Lo que esta regla evita es el dedazo evidente.
            'rfc' => [
                'nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/',
                Rule::unique('suppliers', 'rfc')->where('tenant_id', $tenantId),
            ],

            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:160'],

            // `null` es «no se sabe» y cero es «de contado»: son cosas distintas y las dos se admiten.
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un proveedor con ese código.',
            'code.regex' => 'El código admite letras, números, punto, guion y guion bajo.',
            'rfc.regex' => 'Ese RFC no tiene la forma correcta: 12 caracteres para persona moral, 13 para física.',
            'rfc.unique' => 'Ya hay un proveedor con ese RFC. Es la misma persona moral: búscala en lugar de '
                .'capturarla otra vez, o las compras se repartirán entre dos fichas.',
            'payment_terms_days.max' => 'Un plazo de más de un año no parece un plazo de crédito.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código',
            'legal_name' => 'la razón social',
            'trade_name' => 'el nombre comercial',
            'rfc' => 'el RFC',
            'contact_name' => 'el contacto',
            'phone' => 'el teléfono',
            'email' => 'el correo',
            'payment_terms_days' => 'los días de crédito',
            'notes' => 'las notas',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('code')) {
            $normalized['code'] = mb_strtoupper(trim((string) $this->input('code')));
        }

        // Mayúsculas y sin espacios: un RFC es siempre así, y la columna es `ascii_bin` — sin normalizar, `abc` y
        // `ABC` serían dos proveedores distintos y el único no los atraparía.
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

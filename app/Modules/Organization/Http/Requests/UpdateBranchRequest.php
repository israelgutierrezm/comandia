<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de sucursal.
 *
 * El `code` NO se puede cambiar, y no es una omisión: el código entra en los folios ya
 * emitidos (§7). Cambiarlo dejaría documentos históricos con una serie que ya no corresponde
 * a ninguna sucursal existente, y la foliación sin huecos perdería su significado. Si un
 * tenant se equivocó al capturarlo, la salida es dar de baja la sucursal y crear otra —una
 * operación visible— en lugar de reescribir el pasado en silencio.
 */
final class UpdateBranchRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone'],

            // Sólo un almacén del mismo tenant, y la unicidad estructural la da la columna:
            // una sucursal tiene una, luego a lo más un almacén por defecto.
            'default_warehouse_ulid' => [
                'sometimes', 'nullable', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'street' => ['sometimes', 'nullable', 'string', 'max:160'],
            'exterior_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'interior_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:120'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'size:5', 'regex:/^\d{5}$/'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Rechazo explícito en lugar de descarte silencioso: quien intenta renombrar el
            // código tiene que enterarse de por qué no puede.
            'code' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'El código de la sucursal no se puede cambiar: entra en los folios ya emitidos. Da de baja la sucursal y crea otra.',
            'timezone.timezone' => 'La zona horaria no es válida. Ejemplo: America/Mexico_City.',
            'default_warehouse_ulid.exists' => 'El almacén indicado no existe.',
            'postal_code.regex' => 'El código postal debe tener cinco dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'timezone' => 'la zona horaria',
            'default_warehouse_ulid' => 'el almacén por defecto',
            'postal_code' => 'el código postal',
        ];
    }
}

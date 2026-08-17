<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de almacén (D11).
 *
 * La coherencia entre `kind` y la sucursal se valida aquí **y** la impone un `CHECK` en la
 * base. No es redundancia inútil: la validación da un mensaje en español que el usuario
 * entiende, y el `CHECK` garantiza que ningún otro camino —un seeder, una importación, una
 * consulta a mano— pueda crear un almacén central mal marcado que surtiría a todas las
 * sucursales sin que nadie lo decidiera.
 */
final class StoreWarehouseRequest extends FormRequest
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
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('warehouses', 'code')->where('tenant_id', $tenantId),
            ],

            'name' => ['required', 'string', 'max:120'],

            'kind' => ['required', Rule::enum(WarehouseKind::class)],

            // Obligatoria si es almacén de sucursal, prohibida si es central. Es la misma
            // condición del CHECK, expresada para el usuario.
            'branch_ulid' => [
                'required_if:kind,'.WarehouseKind::Branch->value,
                'prohibited_if:kind,'.WarehouseKind::Central->value,
                'nullable', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un almacén con ese código.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
            'branch_ulid.required_if' => 'Un almacén de sucursal necesita indicar a qué sucursal pertenece.',
            'branch_ulid.prohibited_if' => 'Un almacén central no pertenece a ninguna sucursal: surte a todas.',
            'branch_ulid.exists' => 'La sucursal indicada no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código',
            'name' => 'el nombre',
            'kind' => 'el tipo de almacén',
            'branch_ulid' => 'la sucursal',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}

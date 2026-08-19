<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de área de preparación.
 *
 * A diferencia del almacén, el área **sí** puede cambiar de qué almacén descuenta: es
 * exactamente el ajuste que D11 prevé cuando un tenant pasa de "un almacén por sucursal" a
 * "consumo fino por área". Lo que no puede es cambiar de sucursal ni de código: el área es
 * destino de comandas ya impresas.
 */
final class UpdatePreparationAreaRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],

            'warehouse_ulid' => [
                'sometimes', 'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            // La impresora por donde salen las comandas de esta área. `null` la desasigna, y es un valor legítimo:
            // un área puede dejar de imprimir sin dejar de existir.
            'printer_ulid' => [
                'sometimes', 'nullable', 'string', 'size:26',
                Rule::exists('printers', 'ulid')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'branch_ulid' => ['prohibited'],
            'code' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('warehouse_ulid')) {
                return;
            }

            /** @var PreparationArea $area */
            $area = $this->route('preparation_area');

            $warehouse = Warehouse::findByUlid($this->string('warehouse_ulid')->toString());

            if ($warehouse === null) {
                return;
            }

            if (! $warehouse->isCentral() && $warehouse->branch_id !== $area->branch_id) {
                $validator->errors()->add(
                    'warehouse_ulid',
                    'Ese almacén no es alcanzable desde la sucursal de esta área: debe ser un '
                    .'almacén de la misma sucursal o un almacén central.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.prohibited' => 'Un área no cambia de sucursal: es destino de comandas ya emitidas. Da de baja el área y crea otra.',
            'code.prohibited' => 'El código del área no se puede cambiar.',
            'warehouse_ulid.exists' => 'El almacén indicado no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'sort_order' => 'el orden',
            'warehouse_ulid' => 'el almacén',
        ];
    }
}

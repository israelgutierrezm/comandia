<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de área de preparación (§3, D11).
 *
 * El almacén es obligatorio: el descuento por receta corre en la cola `critical` y no debe
 * contener lógica de adivinanza. Si el área no dijera de dónde descuenta, el job tendría que
 * suponerlo, y una suposición en el camino del kardex es una existencia incorrecta.
 */
final class StorePreparationAreaRequest extends FormRequest
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

            'warehouse_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')->where('tenant_id', $tenantId),
            ],

            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * Reglas que cruzan dos campos y por tanto no caben en `rules()`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branch = Branch::findByUlid((string) $this->input('branch_ulid'));
            $warehouse = Warehouse::findByUlid((string) $this->input('warehouse_ulid'));

            if ($branch === null || $warehouse === null) {
                return;
            }

            // El almacén tiene que ser alcanzable desde la sucursal del área: el propio de la
            // sucursal, o un central —que surte a todas (D11)—. Sin esta comprobación, una
            // cocina de la sucursal Centro podría quedar descontando del almacén de la
            // sucursal Sur, y el error se manifestaría como existencias mal en dos sitios a
            // la vez.
            $alcanzable = $warehouse->isCentral() || $warehouse->branch_id === $branch->id;

            if (! $alcanzable) {
                $validator->errors()->add(
                    'warehouse_ulid',
                    'Ese almacén no es alcanzable desde esta sucursal: debe ser un almacén de la '
                    .'misma sucursal o un almacén central.',
                );
            }

            $codigoRepetido = PreparationArea::query()
                ->where('branch_id', $branch->id)
                ->where('code', mb_strtoupper((string) $this->input('code')))
                ->exists();

            if ($codigoRepetido) {
                $validator->errors()->add('code', 'Ya existe un área con ese código en esta sucursal.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.required' => 'Indica a qué sucursal pertenece el área.',
            'branch_ulid.exists' => 'La sucursal indicada no existe.',
            'warehouse_ulid.required' => 'Indica de qué almacén descuenta esta área.',
            'warehouse_ulid.exists' => 'El almacén indicado no existe.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'la sucursal',
            'warehouse_ulid' => 'el almacén',
            'code' => 'el código',
            'name' => 'el nombre',
            'sort_order' => 'el orden',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}

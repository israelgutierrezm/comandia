<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un motivo de merma (D27).
 *
 * `requires_evidence` es una **política del negocio** que se declara hoy aunque la subida de archivos llegue en la
 * Iteración 11 (P5): «la merma por robo siempre lleva foto» es una decisión que el negocio ya puede tomar, y la UI
 * puede advertirla antes de que exista el almacenamiento.
 *
 * Es distinto de crear una columna `evidence_path` vacía, que P5 recomendó NO hacer: una columna que nadie escribe es
 * una promesa a medias; una política declarada es una decisión tomada.
 */
final class StoreWasteReasonRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('waste_reasons', 'name')->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'requires_evidence' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un motivo de merma con ese nombre. Dos motivos con el mismo nombre '.
                'volverían ambiguo cualquier reporte agrupado por motivo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'requires_evidence' => 'la exigencia de evidencia',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}

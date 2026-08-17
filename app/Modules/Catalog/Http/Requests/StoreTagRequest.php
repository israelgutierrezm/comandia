<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de etiqueta libre (D19).
 */
final class StoreTagRequest extends FormRequest
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
            // Único por tenant con la colación de la base, que aquí es lo correcto: "Sin gluten" y
            // "sin gluten" son la misma etiqueta, y permitir las dos sería un error de captura que el
            // usuario no entendería al ver su lista duplicada.
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('tags', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una etiqueta con ese nombre.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'el nombre'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}

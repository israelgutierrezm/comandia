<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de sucursal.
 *
 * La autorización NO se resuelve aquí: la aplica el middleware `can.write` de la ruta, que
 * pasa por el servicio de contexto (D9). Poner la verificación en `authorize()` del Form
 * Request tentaría a usar `$this->user()->can()`, que evalúa la suma de roles — justo lo que
 * el proyecto prohíbe.
 */
final class StoreBranchRequest extends FormRequest
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
            // El código entra en el folio de los documentos (§7), así que su unicidad por
            // tenant no es cosmética: dos sucursales con el mismo código producirían folios
            // ambiguos. La regla `unique` se acota al tenant a mano porque el global scope de
            // Eloquent no aplica al validador.
            'code' => [
                'required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'name' => ['required', 'string', 'max:120'],

            // Identificador IANA validado de verdad: una zona horaria inválida rompería el
            // cálculo de "el día" en los cortes, y el error aparecería semanas después en un
            // reporte que no cuadra.
            'timezone' => ['required', 'string', 'timezone'],

            'street' => ['nullable', 'string', 'max:160'],
            'exterior_number' => ['nullable', 'string', 'max:20'],
            'interior_number' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'municipality' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'size:5', 'regex:/^\d{5}$/'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe una sucursal con ese código.',
            'code.regex' => 'El código sólo admite letras, números y guiones.',
            'timezone.timezone' => 'La zona horaria no es válida. Ejemplo: America/Mexico_City.',
            'postal_code.regex' => 'El código postal debe tener cinco dígitos.',
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
            'timezone' => 'la zona horaria',
            'postal_code' => 'el código postal',
        ];
    }

    protected function prepareForValidation(): void
    {
        // El código se guarda en `ascii_bin` (D58) y entra en el folio: normalizarlo a
        // mayúsculas aquí evita que `cen` y `CEN` sean dos sucursales distintas.
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }
}

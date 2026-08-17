<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Perfil laboral: alta y edición (§4.1, capa 3; D77).
 *
 * CURP y RFC son únicos por tenant, y esa unicidad depende de que lleguen normalizados a
 * mayúsculas: las columnas son `ascii_bin`, así que sin normalizar `goma850101...` y
 * `GOMA850101...` serían dos empleados distintos con la misma CURP.
 */
final class UpsertEmployeeProfileRequest extends FormRequest
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

        /** @var TenantMembership $membership */
        $membership = $this->route('membership');

        $perfilExistente = $membership->employeeProfile?->id;

        return [
            'legal_first_name' => ['required', 'string', 'max:60'],
            'legal_paternal_surname' => ['required', 'string', 'max:60'],
            // Nullable porque las personas extranjeras no tienen apellido materno.
            'legal_maternal_surname' => ['nullable', 'string', 'max:60'],

            'is_foreigner' => ['boolean'],

            // La CURP acepta ausencia para personas extranjeras (§4.1). Cuando viene, tiene el
            // formato del registro nacional.
            'curp' => [
                'nullable', 'string', 'size:18',
                'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
                Rule::unique('employee_profiles', 'curp')
                    ->where('tenant_id', $tenantId)
                    ->ignore($perfilExistente),
            ],

            'rfc' => [
                'nullable', 'string', 'min:12', 'max:13',
                // 13 para persona física, 12 para moral.
                'regex:/^([A-Z&Ñ]{3,4})\d{6}[A-Z0-9]{3}$/',
                Rule::unique('employee_profiles', 'rfc')
                    ->where('tenant_id', $tenantId)
                    ->ignore($perfilExistente),
            ],

            'nss' => ['nullable', 'string', 'size:11', 'regex:/^\d{11}$/'],

            'birth_date' => ['nullable', 'date', 'before:today'],
            'hired_at' => ['nullable', 'date'],
            'terminated_at' => ['nullable', 'date', 'after_or_equal:hired_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'curp.size' => 'La CURP debe tener 18 caracteres.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'curp.unique' => 'Ya hay otra persona registrada con esa CURP.',
            'rfc.regex' => 'El RFC no tiene un formato válido.',
            'rfc.unique' => 'Ya hay otra persona registrada con ese RFC.',
            'nss.regex' => 'El NSS debe tener 11 dígitos.',
            'terminated_at.after_or_equal' => 'La fecha de baja no puede ser anterior a la de alta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'legal_first_name' => 'el nombre legal',
            'legal_paternal_surname' => 'el apellido paterno',
            'legal_maternal_surname' => 'el apellido materno',
            'curp' => 'la CURP',
            'rfc' => 'el RFC',
            'nss' => 'el NSS',
            'birth_date' => 'la fecha de nacimiento',
            'hired_at' => 'la fecha de alta',
            'terminated_at' => 'la fecha de baja',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['curp', 'rfc', 'nss'] as $campo) {
            if ($this->filled($campo)) {
                $merge[$campo] = mb_strtoupper(trim((string) $this->input($campo)));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}

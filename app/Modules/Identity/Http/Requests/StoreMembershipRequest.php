<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de personal (§4.1).
 *
 * Cubre los dos casos que el producto exige y que suelen tratarse como uno solo:
 *
 *   - **Con credenciales:** correo y contraseña. La persona iniciará sesión.
 *   - **Sin credenciales:** ni correo ni contraseña, pero perfil de empleado obligatorio. Es el
 *     lavaloza que está en nómina y jamás entra al sistema.
 *
 * El segundo caso es la razón de que `email` sea opcional, y el perfil obligatorio en ese caso
 * es el invariante I1 (D66): sin usuario y sin perfil, la persona no tendría nombre.
 */
final class StoreMembershipRequest extends FormRequest
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
            // Único en TODO el SaaS: una persona con dos restaurantes tiene un solo usuario
            // global. Aquí no se valida como único —el correo puede existir ya y se reutiliza—;
            // lo que se rechaza en el servicio es darla de alta dos veces en el mismo tenant.
            'email' => ['nullable', 'email', 'max:150'],

            // Obligatoria si hay correo: un usuario nuevo sin contraseña no podría entrar, y
            // dejarla vacía crearía una cuenta inaccesible que parece funcional.
            'password' => ['required_with:email', 'nullable', 'string', 'min:10', 'max:255'],

            'first_name' => ['required', 'string', 'max:60'],
            'paternal_surname' => ['required', 'string', 'max:60'],
            'maternal_surname' => ['nullable', 'string', 'max:60'],

            'employee_code' => [
                'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('tenant_memberships', 'employee_code')->where('tenant_id', $tenantId),
            ],

            'has_all_branches' => ['boolean'],

            'branch_ulids' => ['array'],
            'branch_ulids.*' => [
                'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            'role_ulids' => ['array'],
            'role_ulids.*' => [
                'string', 'size:26',
                Rule::exists('roles', 'ulid')->where('tenant_id', $tenantId),
            ],

            // Perfil de empleado. Obligatorio cuando no hay correo (invariante I1).
            'employee_profile' => ['required_without:email', 'nullable', 'array'],
            'employee_profile.legal_first_name' => ['required_with:employee_profile', 'string', 'max:60'],
            'employee_profile.legal_paternal_surname' => ['required_with:employee_profile', 'string', 'max:60'],
            'employee_profile.legal_maternal_surname' => ['nullable', 'string', 'max:60'],
            'employee_profile.is_foreigner' => ['boolean'],
            'employee_profile.curp' => ['nullable', 'string', 'size:18'],
            'employee_profile.rfc' => ['nullable', 'string', 'min:12', 'max:13'],
            'employee_profile.nss' => ['nullable', 'string', 'size:11'],
            'employee_profile.birth_date' => ['nullable', 'date', 'before:today'],
            'employee_profile.hired_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required_with' => 'Una persona con acceso al sistema necesita contraseña.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'employee_code.unique' => 'Ya existe alguien con ese código de empleado.',
            'employee_code.regex' => 'El código de empleado sólo admite letras, números y guiones.',
            'employee_profile.required_without' => 'Una persona sin correo necesita perfil de empleado: es de donde sale su nombre.',
            'employee_profile.curp.size' => 'La CURP debe tener 18 caracteres.',
            'employee_profile.rfc.min' => 'El RFC debe tener 12 o 13 caracteres.',
            'branch_ulids.*.exists' => 'Alguna de las sucursales indicadas no existe.',
            'role_ulids.*.exists' => 'Alguno de los roles indicados no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'el correo',
            'password' => 'la contraseña',
            'first_name' => 'el nombre',
            'paternal_surname' => 'el apellido paterno',
            'maternal_surname' => 'el apellido materno',
            'employee_code' => 'el código de empleado',
            'employee_profile' => 'el perfil de empleado',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->filled('employee_code')) {
            $merge['employee_code'] = mb_strtoupper((string) $this->input('employee_code'));
        }

        // CURP, RFC y NSS se guardan en `ascii_bin` (D77): normalizarlos a mayúsculas aquí es lo
        // que hace que la unicidad por tenant funcione de verdad. Sin esto, `goma...` y
        // `GOMA...` serían dos empleados distintos con la misma CURP.
        foreach (['curp', 'rfc', 'nss'] as $campo) {
            if ($this->filled("employee_profile.{$campo}")) {
                $perfil = (array) $this->input('employee_profile', []);
                $perfil[$campo] = mb_strtoupper((string) $perfil[$campo]);
                $merge['employee_profile'] = $perfil;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}

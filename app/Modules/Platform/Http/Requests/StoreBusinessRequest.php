<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de un negocio desde la plataforma. La autorización la aplica el middleware `EnsureSuperAdmin` en la ruta; aquí
 * sólo se valida la forma de los datos que consume `ProvisionTenant`.
 */
final class StoreBusinessRequest extends FormRequest
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
            'business_name' => ['required', 'string', 'max:150'],
            'owner_email' => ['required', 'email', 'max:150'],
            'owner_first_name' => ['required', 'string', 'max:80'],
            'owner_paternal_surname' => ['required', 'string', 'max:80'],
            'owner_maternal_surname' => ['nullable', 'string', 'max:80'],
            'plain_password' => ['required', 'string', 'min:8', 'max:100'],
            'branch_name' => ['nullable', 'string', 'max:120'],
            'branch_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'El nombre del negocio es obligatorio.',
            'owner_email.required' => 'El correo del dueño es obligatorio.',
            'owner_email.email' => 'El correo del dueño no tiene un formato válido.',
            'owner_first_name.required' => 'El nombre del dueño es obligatorio.',
            'owner_paternal_surname.required' => 'El apellido paterno del dueño es obligatorio.',
            'plain_password.required' => 'La contraseña inicial es obligatoria.',
            'plain_password.min' => 'La contraseña inicial debe tener al menos 8 caracteres.',
        ];
    }
}

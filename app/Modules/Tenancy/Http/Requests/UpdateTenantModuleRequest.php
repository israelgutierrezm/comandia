<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activa o desactiva un módulo. El nombre del módulo viaja en la ruta y lo valida el servicio contra el registro
 * declarativo; aquí sólo se exige el booleano.
 */
final class UpdateTenantModuleRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
        ];
    }
}

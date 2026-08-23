<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Guarda una vista de reporte. Sólo el nombre es obligatorio; el resto son los parámetros del reporte (agrupación,
 * filtros), que se guardan tal cual y los valida el motor cuando la vista se ejecuta.
 */
final class StoreSavedViewRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:80'],
        ];
    }
}

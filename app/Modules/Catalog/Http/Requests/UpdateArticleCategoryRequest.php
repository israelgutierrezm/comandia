<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de categoría.
 *
 * `parent_ulid` **no** está entre los campos aceptados: mover una subcategoría de padre cambiaría la
 * clasificación de todos sus artículos de golpe. Ver el controlador.
 */
final class UpdateArticleCategoryRequest extends FormRequest
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
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
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
            'status' => 'el estado',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de categoría de artículos (D18: dos niveles).
 *
 * El padre llega como ULID y no como PK: la API nunca expone identificadores secuenciales (§7). Se
 * resuelve con el global scope aplicado, así que un ULID de otro negocio simplemente no existe — y el
 * mensaje de error es el mismo que para un ULID inventado, que es lo correcto: no se confirma la
 * existencia de un recurso ajeno.
 */
final class StoreArticleCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],

            'parent_ulid' => [
                'nullable', 'string', 'size:26',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parent = ArticleCategory::findByUlid((string) $value);

                    if ($parent === null) {
                        $fail('La categoría padre no existe.');

                        return;
                    }

                    // Ésta es la regla que el CHECK de la migración NO puede imponer: un CHECK no
                    // consulta otras filas, así que no puede saber el nivel del padre. D18 dice dos
                    // niveles y un tercero exige una decisión de producto, no una migración.
                    if (! $parent->canBeParent()) {
                        $fail('Sólo se admiten dos niveles de categoría: esta ya es una subcategoría.');
                    }
                },
            ],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'parent_ulid' => 'la categoría padre',
            'sort_order' => 'el orden',
        ];
    }
}

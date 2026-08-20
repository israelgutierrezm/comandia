<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Requests;

use App\Modules\Finance\Infrastructure\Models\ExpenseCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de categoría de gasto.
 *
 * Un solo Form Request para las dos operaciones porque la forma es idéntica —un nombre y un orden— y las reglas no
 * dependen de si la categoría ya existe. Lo único que cambia es que al editar el nombre se compara contra las demás y
 * no contra todas.
 */
final class SaveExpenseCategoryRequest extends FormRequest
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
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            'is_system' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('name')) {
                return;
            }

            $query = ExpenseCategory::query()->where('name', $this->string('name')->toString());

            // Al editar, la categoría no choca consigo misma.
            $actual = $this->route('expenseCategory');

            if ($actual instanceof ExpenseCategory) {
                $query->whereKeyNot($actual->id);
            }

            if ($query->exists()) {
                // Dos categorías con el mismo nombre partirían el mismo gasto en dos renglones del reporte, que es
                // justo lo que un catálogo existe para evitar.
                $validator->errors()->add('name', 'Ya existe una categoría de gasto con ese nombre.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Para activar o desactivar una categoría usa su acción propia.',
            'is_system.prohibited' => 'Una categoría del sistema no se declara: la siembra el alta del negocio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'el nombre', 'sort_order' => 'el orden'];
    }
}

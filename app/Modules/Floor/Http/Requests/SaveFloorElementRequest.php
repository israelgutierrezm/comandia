<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un elemento decorativo del salón (ADR-011).
 *
 * El `kind` se fija al crear y no cambia: un muro no se convierte en rótulo —eso es borrarlo y crear otro—. La geometría
 * es opcional: al crear, el servidor centra el elemento con un tamaño por omisión según su tipo; al editar, llega la que
 * el editor arrastró. Coordenadas en centímetros (ADR-003), como las mesas.
 */
final class SaveFloorElementRequest extends FormRequest
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
        $creando = $this->isMethod('POST');

        return [
            'kind' => $creando ? ['required', Rule::in(['wall', 'door', 'label'])] : ['prohibited'],

            // Sólo el rótulo lo usa; un muro con texto no rompe nada, pero el front sólo lo pinta en `label`.
            'text' => ['sometimes', 'nullable', 'string', 'max:120'],

            'x' => ['sometimes', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'y' => ['sometimes', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'width' => ['sometimes', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],
            'height' => ['sometimes', 'numeric', 'min:1', 'max:5000', 'decimal:0,2'],
            'rotation' => ['sometimes', 'numeric', 'min:0', 'max:359.99', 'decimal:0,2'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.prohibited' => 'El tipo de un elemento no se cambia: bórralo y crea el que quieras.',
            'kind.in' => 'Un elemento es un muro, una puerta o un rótulo.',
        ];
    }
}

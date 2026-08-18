<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Disponibilidad propia de un artículo en una sucursal (§6.1).
 *
 * `is_available_in_pos` **nullable** con un significado explícito: `null` = volver a heredar la del negocio.
 * Es la misma semántica que la cascada de configuración, y hace falta un valor que diga "quita el override"
 * distinto de `false`, que dice "no está disponible aquí".
 *
 * Por eso la regla es `present` y no `required`: `required` rechazaría el `null` que precisamente sirve para
 * volver a heredar.
 */
final class SetBranchAvailabilityRequest extends FormRequest
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
            'is_available_in_pos' => ['present', 'nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_available_in_pos.present' => 'Indica la disponibilidad, o null para volver a heredar la del negocio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['is_available_in_pos' => 'la disponibilidad'];
    }
}

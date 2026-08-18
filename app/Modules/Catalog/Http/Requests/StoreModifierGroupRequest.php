<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de un grupo de modificadores (D7).
 *
 * Las reglas de selección son la parte delicada: dos combinaciones dejarían al POS sin poder comandar y sin
 * decir por qué. Se validan aquí para dar el mensaje y hay además un `CHECK` en la base para dar la garantía —
 * la validación protege el camino HTTP, el CHECK protege importaciones y seeders.
 */
final class StoreModifierGroupRequest extends FormRequest
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
        $existing = $this->route('modifier_group');

        return [
            'name' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string', 'max:80',
                Rule::unique('modifier_groups', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($existing?->id),
            ],

            'is_required' => ['sometimes', 'boolean'],
            'min_selections' => ['sometimes', 'integer', 'min:0', 'max:255'],

            // `null` = sin límite, que es distinto de un número alto: "elige los que quieras" y "elige hasta
            // 255" se cuentan igual en la práctica, pero sólo el primero se puede explicar en una pantalla.
            'max_selections' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],

            // D7 literal: "selección múltiple con cantidades (ej. 3 shots)".
            'allows_quantity' => ['sometimes', 'boolean'],

            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $existing = $this->route('modifier_group');

                // Al editar sólo llega lo que cambia, así que las reglas se evalúan sobre el estado FINAL:
                // subir el mínimo sin tocar el máximo puede invalidar una combinación que era válida.
                $min = $this->integer('min_selections', $existing?->min_selections ?? 0);
                $required = $this->boolean('is_required', $existing?->is_required ?? false);

                $max = $this->has('max_selections')
                    ? ($this->input('max_selections') === null ? null : $this->integer('max_selections'))
                    : $existing?->max_selections;

                if ($max !== null && $max < $min) {
                    $validator->errors()->add(
                        'max_selections',
                        'El máximo no puede ser menor que el mínimo: ninguna selección sería válida y el punto '.
                        'de venta no podría comandar el platillo.'
                    );
                }

                if ($required && $min < 1) {
                    $validator->errors()->add(
                        'min_selections',
                        'Un grupo obligatorio necesita al menos una selección mínima; si no, no obliga a nada.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un grupo de modificadores con ese nombre.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'is_required' => 'la obligatoriedad',
            'min_selections' => 'el mínimo de selecciones',
            'max_selections' => 'el máximo de selecciones',
            'allows_quantity' => 'la captura por cantidad',
        ];
    }
}

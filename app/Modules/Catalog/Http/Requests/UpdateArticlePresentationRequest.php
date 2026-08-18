<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de una presentación de compra.
 *
 * ## `quantity_in_base_unit` es INMUTABLE, y por la misma razón que la unidad base (D96)
 *
 * Es el **divisor** con el que se calcularon los costos ya capturados a través de esta presentación:
 * «pagué $480 por la caja» se convirtió en `$0.0400/g` dividiendo por 12 000. Cambiar la cantidad a 24 000
 * no corregiría un costo pasado — reinterpretaría todos, y a la mitad de su valor, sin que ninguna fila del
 * historial de costos cambie ni deje rastro de por qué dejó de cuadrar.
 *
 * Si el proveedor cambia el tamaño de la caja, **es otra presentación**. Dar de baja la anterior conserva la
 * historia y es lo que la deja auditable.
 *
 * Antes se reutilizaba aquí el Form Request del alta, así que la cantidad se podía cambiar con un `PATCH` y
 * nada lo impedía. Se descubrió al escribir la primera prueba que llamó a este endpoint, en la auditoría de
 * cierre de la Iteración 2 — el endpoint no se había ejercitado nunca.
 *
 * Se declara `prohibited` en lugar de ignorarse en silencio: quien la manda cree que va a cambiar algo, y
 * un cambio que se acepta y no ocurre es peor que un rechazo.
 */
final class UpdateArticlePresentationRequest extends FormRequest
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
            'barcode' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],

            'quantity_in_base_unit' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_in_base_unit.prohibited' => 'La cantidad no se puede cambiar: es el divisor con el que '.
                'se calcularon los costos ya capturados por esta presentación. Si cambió el tamaño, crea otra '.
                'presentación y da de baja ésta.',
            'barcode.regex' => 'El código de barras sólo admite letras, números y guiones.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'barcode' => 'el código de barras',
            'quantity_in_base_unit' => 'la cantidad en unidad base',
            'status' => 'el estado',
        ];
    }
}
